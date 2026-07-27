<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Exceptions\CatalogException;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->context = app(TenantContext::class);
    $this->context->set($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('computes the cost of a recipe from its ingredients', function (): void {
    // Mojito: 60 ml de ron a RD$0.80/ml + 30 g de azúcar a RD$0.10/g + 1 limón a RD$15
    $ron = InventoryItem::factory()->create(['name' => 'Ron blanco', 'cost_cents' => 80]);
    $azucar = InventoryItem::factory()->create(['name' => 'Azúcar', 'cost_cents' => 10]);
    $limon = InventoryItem::factory()->unit(1500)->create(['name' => 'Limón']);

    $mojito = Product::factory()->recipe()->create(['name' => 'Mojito', 'price_cents' => 45000]);
    $mojito->recipeItems()->createMany([
        ['inventory_item_id' => $ron->id, 'quantity' => 60],
        ['inventory_item_id' => $azucar->id, 'quantity' => 30],
        ['inventory_item_id' => $limon->id, 'quantity' => 1],
    ]);

    $mojito->load('recipeItems.inventoryItem');

    // 60×80 + 30×10 + 1×1500 = 4800 + 300 + 1500 = 6600 (RD$66)
    expect($mojito->costCents())->toBe(6600)
        ->and($mojito->marginCents())->toBe(38400)
        ->and($mojito->marginPercent())->toBe(85.3);
});

it('supports fractional quantities in recipes', function (): void {
    $esencia = InventoryItem::factory()->create(['name' => 'Esencia', 'cost_cents' => 1000]);

    $producto = Product::factory()->recipe()->create(['price_cents' => 10000]);
    $producto->recipeItems()->create(['inventory_item_id' => $esencia->id, 'quantity' => 2.5]);

    expect($producto->load('recipeItems.inventoryItem')->costCents())->toBe(2500);
});

it('takes the cost of a simple tracked product from its own item', function (): void {
    $cervezaInsumo = InventoryItem::factory()->unit(9000)->create(['name' => 'Cerveza Presidente und.']);

    $cerveza = Product::factory()->create([
        'name' => 'Presidente',
        'price_cents' => 35000,
        'track_stock' => true,
        'inventory_item_id' => $cervezaInsumo->id,
    ]);

    expect($cerveza->costCents())->toBe(9000)
        ->and($cerveza->marginCents())->toBe(26000);
});

it('has no cost when a simple product is not linked to an item', function (): void {
    $servicio = Product::factory()->create(['price_cents' => 10000]);

    expect($servicio->costCents())->toBeNull()
        ->and($servicio->marginCents())->toBeNull()
        ->and($servicio->marginPercent())->toBeNull();
});

it('refuses recipe lines on a simple product', function (): void {
    $insumo = InventoryItem::factory()->create();
    $simple = Product::factory()->create();

    $simple->recipeItems()->create(['inventory_item_id' => $insumo->id, 'quantity' => 10]);
})->throws(CatalogException::class);

it('refuses an ingredient from another account', function (): void {
    $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $insumoAjeno = $this->context->runAs($otro, fn () => InventoryItem::factory()->create());

    $receta = Product::factory()->recipe()->create();
    $receta->recipeItems()->create(['inventory_item_id' => $insumoAjeno->id, 'quantity' => 10]);
})->throws(CatalogException::class);

it('keeps each accounts catalog invisible to the other', function (): void {
    Product::factory()->create(['name' => 'Mío']);

    $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $this->context->set($otro);

    expect(Product::count())->toBe(0)
        ->and(InventoryItem::count())->toBe(0);
});

it('exposes the type as a decision, not a mutable field', function (): void {
    $simple = Product::factory()->create();

    expect($simple->type)->toBe(ProductType::Simple)
        ->and(Product::factory()->recipe()->create()->type)->toBe(ProductType::Recipe);
});

it('reports an empty recipe as unknown cost, never zero', function (): void {
    $receta = Product::factory()->recipe()->create(['price_cents' => 10000]);

    expect($receta->load('recipeItems.inventoryItem')->costCents())->toBeNull()
        ->and($receta->marginPercent())->toBeNull();
});

it('reports unknown cost when a recipe ingredient does not resolve', function (): void {
    $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $insumoAjeno = $this->context->runAs($otro, fn () => InventoryItem::factory()->create(['cost_cents' => 99999]));

    $receta = Product::factory()->recipe()->create(['price_cents' => 10000]);
    // Sembrado a nivel de base para simular la corrupción: una línea cuyo
    // insumo el tenant no puede ver. El costo debe salir null, no 0.
    DB::table('recipe_items')->insert([
        'tenant_id' => $this->tenant->id,
        'product_id' => $receta->id,
        'inventory_item_id' => $insumoAjeno->id,
        'quantity' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($receta->load('recipeItems.inventoryItem')->costCents())->toBeNull()
        ->and($receta->marginPercent())->toBeNull();
});

it('refuses to change a products type after creation', function (): void {
    $mojito = Product::factory()->recipe()->create();

    $mojito->update(['type' => ProductType::Simple]);
})->throws(CatalogException::class);

it('refuses to move a recipe line to another accounts ingredient', function (): void {
    $ron = InventoryItem::factory()->create(['cost_cents' => 80]);
    $receta = Product::factory()->recipe()->create();
    $linea = $receta->recipeItems()->create(['inventory_item_id' => $ron->id, 'quantity' => 60]);

    $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $insumoAjeno = $this->context->runAs($otro, fn () => InventoryItem::factory()->create());

    $linea->update(['inventory_item_id' => $insumoAjeno->id]);
})->throws(CatalogException::class);

it('refuses a product pointing at another accounts category', function (): void {
    $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $categoriaAjena = $this->context->runAs($otro, fn () => Category::factory()->create());

    Product::factory()->create(['category_id' => $categoriaAjena->id]);
})->throws(CatalogException::class);
