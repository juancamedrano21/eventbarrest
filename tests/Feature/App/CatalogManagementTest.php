<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Categories\CategoryResource;
use App\Filament\App\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\ProductResource;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);
    $this->cashier = app(CreateTenantUser::class)($this->tenant, 'Carla', 'carla@bar.test', 'Secreta-2026', Role::Cashier);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('creates an ingredient converting pesos to cents', function (): void {
    signInTo($this, $this->owner, $this->tenant);

    Livewire::test(ListInventoryItems::class)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Ron blanco',
            'base_unit' => 'ml',
            'cost_cents' => 0.80,
        ])
        ->assertHasNoActionErrors();

    expect(InventoryItem::query()->where('name', 'Ron blanco')->sole()->cost_cents)->toBe(80);
});

it('creates a category from its modal', function (): void {
    signInTo($this, $this->owner, $this->tenant);

    Livewire::test(CategoryResource::getPages()['index']->getPage())
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Cócteles',
            'dispatch' => DispatchArea::Bar->value,
        ])
        ->assertHasNoActionErrors();

    expect(Category::query()->where('name', 'Cócteles')->sole()->dispatch)->toBe(DispatchArea::Bar);
});

it('creates a recipe product with its escandallo from the form', function (): void {
    signInTo($this, $this->owner, $this->tenant);

    $categoria = Category::factory()->create(['name' => 'Cócteles']);
    $ron = InventoryItem::factory()->create(['name' => 'Ron blanco', 'cost_cents' => 80]);
    $limon = InventoryItem::factory()->unit(1500)->create(['name' => 'Limón']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Mojito',
            'category_id' => $categoria->id,
            'price_cents' => 450,
            'type' => ProductType::Recipe->value,
            'recipeItems' => [
                ['inventory_item_id' => $ron->id, 'quantity' => 60],
                ['inventory_item_id' => $limon->id, 'quantity' => 1],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $mojito = Product::query()->where('name', 'Mojito')->sole();

    expect($mojito->type)->toBe(ProductType::Recipe)
        ->and($mojito->price_cents)->toBe(45000)
        ->and($mojito->recipeItems()->count())->toBe(2)
        ->and($mojito->load('recipeItems.inventoryItem')->costCents())->toBe(6300);
});

it('creates a simple product linked to its own item', function (): void {
    signInTo($this, $this->owner, $this->tenant);

    $categoria = Category::factory()->create();
    $insumo = InventoryItem::factory()->unit(9000)->create(['name' => 'Presidente und.']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Presidente',
            'category_id' => $categoria->id,
            'price_cents' => 350,
            'type' => ProductType::Simple->value,
            'track_stock' => true,
            'inventory_item_id' => $insumo->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $cerveza = Product::query()->where('name', 'Presidente')->sole();

    expect($cerveza->track_stock)->toBeTrue()
        ->and($cerveza->costCents())->toBe(9000)
        ->and($cerveza->marginCents())->toBe(26000);
});

it('rejects a duplicate product name within the account', function (): void {
    signInTo($this, $this->owner, $this->tenant);

    Product::factory()->create(['name' => 'Mojito']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Mojito',
            'category_id' => Category::factory()->create()->id,
            'price_cents' => 100,
            'type' => ProductType::Simple->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

it('keeps a cashier out of the catalog', function (): void {
    signInTo($this, $this->cashier, $this->tenant);

    expect(ProductResource::canViewAny())->toBeFalse();
    Livewire::test(ListProducts::class)->assertForbidden();
});

it('opens the catalog to organizer accounts too', function (): void {
    $productora = app(CreateTenant::class)('Producciones Caribe', null, TenantType::Organizer);
    $dueno = app(CreateTenantUser::class)($productora, 'Beto', 'beto@prod.test', 'Secreta-2026', Role::Owner);

    signInTo($this, $dueno, $productora);

    expect(ProductResource::canViewAny())->toBeTrue();
    Livewire::test(ListProducts::class)->assertOk();
});
