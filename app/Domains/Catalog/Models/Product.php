<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Exceptions\CatalogException;
use App\Domains\EventManagement\Concerns\BelongsToVendor;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lo que se vende. Un producto sencillo se despacha tal cual (y puede
 * descontar su propio insumo 1:1); uno con receta se prepara con insumos y
 * su costo sale del escandallo.
 *
 * El precio se congela en cada venta (doc 03): cambiarlo aquí nunca altera
 * ventas históricas.
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $inventory_item_id
 * @property string $name
 * @property ProductType $type
 * @property int $price_cents
 * @property bool $track_stock
 * @property bool $active
 * @property bool $itbis_exempt
 * @property-read Category $category
 * @property-read InventoryItem|null $inventoryItem
 * @property-read Collection<int, RecipeItem> $recipeItems
 */
class Product extends Model
{
    use BelongsToTenant;
    use BelongsToVendor;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'inventory_item_id',
        'name',
        'type',
        'price_cents',
        'track_stock',
        'active',
        'itbis_exempt',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'price_cents' => 'integer',
            'track_stock' => 'boolean',
            'active' => 'boolean',
            'itbis_exempt' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // El tipo define costo y consumo: inmutable tras el alta, también
        // fuera del formulario (seeders, imports, código futuro).
        static::updating(function (Product $product): void {
            if ($product->isDirty('type')) {
                throw CatalogException::typeIsImmutable();
            }
        });

        // La categoría y el insumo vinculado nunca cruzan de cuenta. Las
        // consultas van sin scope a propósito: queremos saber de quién son
        // las filas de verdad, no si podemos verlas.
        $assert = function (Product $product): void {
            // getAttribute, no la propiedad tipada: en creating tenant_id
            // puede estar aún sin rellenar por BelongsToTenant.
            if ($product->getAttribute('tenant_id') === null) {
                return;
            }

            // Sin scopes (ni tenant ni vendor): el guard decide con la
            // verdad de las filas, no con la vista del contexto activo.
            $category = Category::query()->withoutGlobalScopes()
                ->whereKey($product->category_id)
                ->first(['tenant_id', 'vendor_id']);

            if ($category === null || $category->tenant_id !== $product->tenant_id) {
                throw CatalogException::categoryOutsideTenant();
            }

            if ($category->getAttribute('vendor_id') !== $product->getAttribute('vendor_id')) {
                throw CatalogException::categoryOutsideVendor();
            }

            if ($product->inventory_item_id !== null) {
                $item = InventoryItem::query()->withoutGlobalScopes()
                    ->whereKey($product->inventory_item_id)
                    ->first(['tenant_id', 'vendor_id']);

                if ($item === null || $item->tenant_id !== $product->tenant_id) {
                    throw CatalogException::ingredientOutsideTenant();
                }

                if ($item->getAttribute('vendor_id') !== $product->getAttribute('vendor_id')) {
                    throw CatalogException::ingredientOutsideVendor();
                }
            }
        };

        static::creating($assert);
        static::updating(function (Product $product) use ($assert): void {
            if ($product->isDirty('category_id') || $product->isDirty('inventory_item_id')) {
                $assert($product);
            }
        });

        // Una receta descuenta por su escandallo, jamás por vínculo directo:
        // el controlador ya lo filtra, esto para seeders e imports futuros.
        $soloSimplesVinculan = function (Product $product): void {
            if ($product->type === ProductType::Recipe && $product->inventory_item_id !== null) {
                throw CatalogException::recipesConsumeThroughTheirRecipe();
            }
        };

        static::creating($soloSimplesVinculan);
        static::updating(function (Product $product) use ($soloSimplesVinculan): void {
            if ($product->isDirty('inventory_item_id')) {
                $soloSimplesVinculan($product);
            }
        });
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return HasMany<RecipeItem, $this>
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /**
     * El costo del producto en centavos, o null si NO SE PUEDE CONOCER —
     * y desconocido nunca se disfraza de cero: una receta vacía o una línea
     * cuyo insumo no resuelve (corrupción) devuelven null y la tabla muestra
     * «—», jamás un margen 100% verde. Fail-closed, como todo aquí.
     */
    public function costCents(): ?int
    {
        if ($this->type === ProductType::Recipe) {
            if ($this->recipeItems->isEmpty()) {
                return null;
            }

            $total = 0.0;

            foreach ($this->recipeItems as $item) {
                if ($item->inventoryItem === null) {
                    return null;
                }

                $total += (float) $item->quantity * $item->inventoryItem->cost_cents;
            }

            return (int) round($total);
        }

        return $this->inventoryItem?->cost_cents;
    }

    /** Margen bruto en centavos, o null si el costo no se conoce. */
    public function marginCents(): ?int
    {
        $cost = $this->costCents();

        return $cost === null ? null : $this->price_cents - $cost;
    }

    /** Margen bruto como porcentaje del precio, o null si no se conoce. */
    public function marginPercent(): ?float
    {
        $margin = $this->marginCents();

        if ($margin === null || $this->price_cents === 0) {
            return null;
        }

        return round($margin / $this->price_cents * 100, 1);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
