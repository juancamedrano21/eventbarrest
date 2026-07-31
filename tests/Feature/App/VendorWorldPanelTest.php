<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Categories\CategoryResource;
use App\Filament\App\Resources\Categories\Pages\ListCategories;
use App\Filament\App\Resources\InventoryItems\InventoryItemResource;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\App\Resources\StockLevels\StockLevelResource;
use App\Filament\App\Resources\Users\UserResource;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * El contrato del mundo de eventos en el panel: cada comercio opera SOLO su
 * catálogo e inventario; el organizador ve el consolidado pero no opera; y
 * el mundo de negocios independientes sigue funcionando como siempre.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));

        $this->cerveceria = app(CreateVendor::class)('La Cervecería');
        $this->tacos = app(CreateVendor::class)('Tacos del Puerto');

        app(InviteVendorToEvent::class)($event, $this->cerveceria);
        app(InviteVendorToEvent::class)($event, $this->tacos);

        $this->barra = outletFor($event, 'Barra', OperatingUnitKind::Bar, $this->cerveceria);
        $this->puesto = outletFor($event, 'Puesto', OperatingUnitKind::Kitchen, $this->tacos);

        $vendors = app(VendorContext::class);

        $vendors->runAs($this->cerveceria, function (): void {
            $cat = Category::create(['name' => 'Tragos', 'dispatch' => DispatchArea::Bar]);
            $this->ron = InventoryItem::create(['name' => 'Ron añejo', 'base_unit' => MeasurementUnit::Milliliter, 'cost_cents' => 0]);
            Product::create(['category_id' => $cat->id, 'name' => 'Cuba Libre', 'type' => ProductType::Simple, 'price_cents' => 40000]);
            app(RegisterPurchase::class)($this->barra, $this->ron, 1000, 95, 'Inicial');
        });

        $vendors->runAs($this->tacos, function (): void {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $carne = InventoryItem::create(['name' => 'Carne al pastor', 'base_unit' => MeasurementUnit::Gram, 'cost_cents' => 0]);
            Product::create(['category_id' => $cat->id, 'name' => 'Taco al pastor', 'type' => ProductType::Simple, 'price_cents' => 25000]);
            app(RegisterPurchase::class)($this->puesto, $carne, 5000, 45, 'Inicial');
        });
    });

    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
    $this->encargada = app(CreateTenantUser::class)($this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->cerveceria);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('shows vendor staff only their own catalog', function (): void {
    $response = $this->actingAs($this->encargada)->get('/app/products');

    $response->assertOk()
        ->assertSee('Cuba Libre')
        ->assertDontSee('Taco al pastor');
});

it('shows the organizer the consolidated catalog of every vendor', function (): void {
    $response = $this->actingAs($this->owner)->get('/app/products');

    $response->assertOk()
        ->assertSee('Cuba Libre')
        ->assertSee('Taco al pastor');
});

// El dueño del evento mira; el comercio opera. La pantalla de crear existe
// solo para quien tiene un comercio activo. (Dos tests separados: encadenar
// usuarios en una misma sesión dispara AuthenticateSession del panel.)
it('lets vendor staff reach the create screen', function (): void {
    expect($this->actingAs($this->encargada)->get('/app/products/create')->getStatusCode())->toBe(200);
});

it('keeps the organizer out of the create screen: reads, never operates', function (): void {
    expect($this->actingAs($this->owner)->get('/app/products/create')->getStatusCode())->toBe(403);
});

it('applies the same rule to categories and inventory items', function (): void {
    signInTo($this, $this->owner, $this->organizer);
    expect(CategoryResource::canCreate())->toBeFalse()
        ->and(InventoryItemResource::canCreate())->toBeFalse()
        ->and(ProductResource::canCreate())->toBeFalse();

    signInTo($this, $this->encargada, $this->organizer);
    expect(CategoryResource::canCreate())->toBeTrue()
        ->and(InventoryItemResource::canCreate())->toBeTrue()
        ->and(ProductResource::canCreate())->toBeTrue();
});

it('scopes stock to the vendor units of the signed-in staff', function (): void {
    signInTo($this, $this->encargada, $this->organizer);

    $units = StockLevelResource::getEloquentQuery()
        ->with('operatingUnit')
        ->get()
        ->pluck('operatingUnit.name')
        ->unique()
        ->values();

    expect($units->all())->toBe(['Barra']);
});

it('keeps the organizer seeing stock of every vendor', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    $units = StockLevelResource::getEloquentQuery()
        ->with('operatingUnit')
        ->get()
        ->pluck('operatingUnit.name')
        ->unique()
        ->sort()
        ->values();

    expect($units->all())->toBe(['Barra', 'Puesto']);
});

it('gives the vendor manager catalog permission via its role', function (): void {
    signInTo($this, $this->encargada, $this->organizer);

    expect($this->encargada->can(Permission::CatalogManage->value))->toBeTrue()
        ->and($this->encargada->can(Permission::EventsManage->value))->toBeFalse()
        ->and($this->encargada->can(Permission::UsersManage->value))->toBeFalse();
});

it('never lets vendor staff manage the team, even by URL', function (): void {
    signInTo($this, $this->encargada, $this->organizer);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and($this->actingAs($this->encargada)->get('/app/users')->getStatusCode())->toBe(403);
});

it('leaves the independent business world operating as always', function (): void {
    $bar = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $owner = app(CreateTenantUser::class)($bar, 'Beto', 'beto@bar.test', 'Secreta-2026', Role::Owner);

    expect($this->actingAs($owner)->get('/app/products/create')->getStatusCode())->toBe(200);
});

// Los modales de una sola página (categorías, insumos) no pasan por una
// página de crear: su puerta es la RESPUESTA de autorización del recurso.
// Estos tests fijan que el organizador la tiene negada y el comercio no.
it('denies the organizer the modal writes at the authorization layer', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    expect(CategoryResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(InventoryItemResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(ProductResource::getCreateAuthorizationResponse()->allowed())->toBeFalse();
});

it('hides the category create action from the organizer and executes it for vendor staff', function (): void {
    signInTo($this, $this->owner, $this->organizer);
    Livewire::test(ListCategories::class)->assertActionHidden(TestAction::make('create')->table());

    signInTo($this, $this->encargada, $this->organizer);
    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('create')->table(), [
            'name' => 'Refrescos',
            'dispatch' => DispatchArea::Bar->value,
        ])
        ->assertHasNoActionErrors();

    expect(Category::query()->withoutGlobalScopes()
        ->where('name', 'Refrescos')
        ->value('vendor_id'))->toBe($this->cerveceria->id);
});

it('hides the stock write actions from the organizer', function (): void {
    signInTo($this, $this->owner, $this->organizer);

    Livewire::test(ListStockLevels::class)
        ->assertActionHidden(TestAction::make('compra')->table())
        ->assertActionHidden(TestAction::make('traslado')->table());
});

it('rejects a duplicate product name within the same vendor but allows it in another', function (): void {
    // La unicidad es por comercio: el «Cuba Libre» de La Cervecería no se
    // repite en La Cervecería, pero Tacos puede tener el suyo.
    signInTo($this, $this->encargada, $this->organizer);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Cuba Libre',
            'category_id' => Category::query()->value('id'),
            'price_cents' => 350,
            'type' => ProductType::Simple->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    $tacoManager = app(CreateTenantUser::class)(
        $this->organizer, 'Tito', 'tito@x.test', 'Secreta-2026', Role::VendorManager, $this->tacos,
    );
    signInTo($this, $tacoManager, $this->organizer);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Cuba Libre',
            'category_id' => Category::query()->value('id'),
            'price_cents' => 300,
            'type' => ProductType::Simple->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->withoutGlobalScopes()
        ->where('name', 'Cuba Libre')
        ->count())->toBe(2);
});
