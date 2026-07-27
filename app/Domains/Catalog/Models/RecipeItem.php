<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Exceptions\CatalogException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del escandallo: cuánto insumo consume el producto, en la unidad
 * base del insumo. Al pagar una orden, estas líneas se convertirán en
 * movimientos de stock (dominio Inventory, hito siguiente).
 *
 * @property int $product_id
 * @property int $inventory_item_id
 * @property numeric-string $quantity
 * @property-read Product $product
 * @property-read InventoryItem|null $inventoryItem Null si la fila cruza de cuenta (corrupción visible)
 */
class RecipeItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'inventory_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // El escandallo solo existe en productos con receta, y nunca cruza
        // de cuenta — ni al crear ni al reapuntar una línea existente.
        static::creating(fn (RecipeItem $item) => static::assertCoherent($item));
        static::updating(function (RecipeItem $item): void {
            if ($item->isDirty('inventory_item_id') || $item->isDirty('product_id')) {
                static::assertCoherent($item);
            }
        });
    }

    /**
     * Las consultas van sin scope a propósito: queremos saber de quién son
     * las filas de verdad, no si podemos verlas.
     */
    protected static function assertCoherent(RecipeItem $item): void
    {
        $product = Product::query()->withoutTenancy()->find($item->product_id);

        if ($product === null || $product->tenant_id !== $item->tenant_id) {
            throw CatalogException::productOutsideTenant();
        }

        if ($product->type !== ProductType::Recipe) {
            throw CatalogException::recipeNeedsARecipeProduct();
        }

        $ingredientTenant = InventoryItem::query()->withoutTenancy()
            ->whereKey($item->inventory_item_id)
            ->value('tenant_id');

        if ($ingredientTenant !== $item->tenant_id) {
            throw CatalogException::ingredientOutsideTenant();
        }
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** Costo de esta línea en centavos, o null si el insumo no resuelve. */
    public function costCents(): ?int
    {
        if ($this->inventoryItem === null) {
            return null;
        }

        return (int) round((float) $this->quantity * $this->inventoryItem->cost_cents);
    }
}
