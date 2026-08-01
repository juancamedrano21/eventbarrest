<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\FoodType;
use App\Domains\Platform\Models\VendorType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * La primera pantalla del panel nuevo (Blade + Preline, ADR-006): el perfil
 * del comercio. Mismas fronteras que siempre: organizador administra,
 * personal de comercio ni lo ve, otra cuenta ni existe.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
    });

    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('renders the profile for the organizer owner', function (): void {
    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$this->vendor->id}")
        ->assertOk()
        ->assertSee('Tacos del Puerto')
        ->assertSee('Equipo del comercio')
        ->assertSee('Puestos de venta');
});

it('keeps vendor staff out of the new panel too', function (): void {
    $staff = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    $this->actingAs($staff)
        ->get("/panel/comercios/{$this->vendor->id}")
        ->assertForbidden();
});

it('never shows a vendor from another account', function (): void {
    $otro = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);
    $ajeno = app(TenantContext::class)->runAs($otro, fn () => app(CreateVendor::class)('Ajeno'));

    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$ajeno->id}")
        ->assertNotFound();
});

it('creates vendor staff from the profile form', function (): void {
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/usuarios", [
            'name' => 'María',
            'username' => 'Maria',
            'email' => 'maria@tacos.test',
            'password' => 'Secreta-2026',
            'role' => 'vendor_manager',
        ])
        ->assertRedirect();

    $maria = User::query()->where('email', 'maria@tacos.test')->sole();
    expect($maria->vendor_id)->toBe($this->vendor->id)
        ->and($maria->username)->toBe('maria');
});

it('invites the vendor to an event with its commission', function (): void {
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/invitar", [
            'event_id' => $this->event->id,
            'commission' => 12.5,
        ])
        ->assertRedirect();

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        expect($this->vendor->events()->first()?->pivot->commission_bps)->toBe(1250);
    });
});

it('creates an outlet already attached to the vendor', function (): void {
    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(InviteVendorToEvent::class)($this->event, $this->vendor),
    );

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/puestos", [
            'event_id' => $this->event->id,
            'name' => 'Barra principal',
            'kind' => 'bar',
        ])
        ->assertRedirect();

    $outlet = EventOutlet::query()->withoutGlobalScopes()->where('name', 'Barra principal')->sole();
    expect($outlet->vendor_id)->toBe($this->vendor->id);
});

it('shows the tabs and the sales numbers of the vendor', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(InviteVendorToEvent::class)($this->event, $this->vendor);
        $outlet = outletFor($this->event, 'Puesto', OperatingUnitKind::Kitchen, $this->vendor);

        app(VendorContext::class)->runAs($this->vendor, function () use ($outlet): void {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $taco = Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 25000]);
            $caja = app(OpenCashSession::class)($outlet, null, 0);
            $orden = app(PlaceOrder::class)($caja, [['product_id' => $taco->id, 'quantity' => 2]], 'perfil-001');
            app(PayOrder::class)($orden, PaymentMethod::Cash, 50000);
        });
    });

    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$this->vendor->id}")
        ->assertOk()
        ->assertSee('Transacciones')
        ->assertSee('Configuraciones')
        ->assertSee('perfil-001')
        ->assertSee('500.00');
});

it('saves the configuration: logo, business type and food type', function (): void {
    Storage::fake('public');
    $tipo = VendorType::query()->firstOrCreate(['name' => 'Bar']);
    $comida = FoodType::query()->create(['name' => 'Mariscos']);

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/datos", [
            'name' => 'Tacos del Puerto',
            'status' => 'active',
            'vendor_type_id' => $tipo->id,
            'food_type_id' => $comida->id,
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])
        ->assertRedirect();

    app(TenantContext::class)->runAs($this->organizer, function () use ($tipo, $comida): void {
        $fresh = $this->vendor->fresh();
        expect($fresh->vendor_type_id)->toBe($tipo->id)
            ->and($fresh->food_type_id)->toBe($comida->id)
            ->and($fresh->logo_path)->not->toBeNull();
        Storage::disk('public')->assertExists($fresh->logo_path);
    });
});

// El dueño de la cuenta OPERA dentro del comercio (decisión 2026-08-01):
// catálogo e inventario desde el perfil, siempre como el comercio.

it('builds the menu: category classified as Bebidas and its product', function (): void {
    // Alimentos = cocina, Bebidas = barra: la clasificación ES el despacho.
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/categorias", [
            'name' => 'Tragos', 'tipo' => 'bebidas',
        ])
        ->assertRedirect();

    $categoria = Category::query()->withoutGlobalScopes()->where('name', 'Tragos')->sole();
    expect($categoria->vendor_id)->toBe($this->vendor->id)
        ->and($categoria->dispatch)->toBe(DispatchArea::Bar);

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos", [
            'name' => 'Cuba Libre',
            'price' => '450.50',
            'category_id' => $categoria->id,
            'kind' => 'simple',
        ])
        ->assertRedirect();

    $producto = Product::query()->withoutGlobalScopes()->where('name', 'Cuba Libre')->sole();
    expect($producto->vendor_id)->toBe($this->vendor->id)
        ->and($producto->price_cents)->toBe(45050);

    // Y el menú lo muestra agrupado con su clasificación.
    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$this->vendor->id}")
        ->assertOk()
        ->assertSee('Bebidas')
        ->assertSee('Cuba Libre');
});

it('lets the owner reprice and deactivate a vendor product, never a foreign one', function (): void {
    $producto = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs($this->vendor, function () {
        $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

        return Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 20000]);
    }));

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos/{$producto->id}", [
            'price' => '275', 'active' => 0,
        ])
        ->assertRedirect();

    $fresh = Product::query()->withoutGlobalScopes()->findOrFail($producto->id);
    expect($fresh->price_cents)->toBe(27500)->and($fresh->active)->toBeFalse();

    // Un producto de OTRO comercio: para este perfil no existe.
    $ajeno = app(TenantContext::class)->runAs($this->organizer, function () {
        $otro = app(CreateVendor::class)('Otro Comercio');

        return app(VendorContext::class)->runAs($otro, function () {
            $cat = Category::create(['name' => 'X', 'dispatch' => DispatchArea::Bar]);

            return Product::create(['category_id' => $cat->id, 'name' => 'Ajeno', 'type' => ProductType::Simple, 'price_cents' => 100]);
        });
    });

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos/{$ajeno->id}", ['price' => '1'])
        ->assertNotFound();
});

it('lets the owner stock the vendor through the ledger', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(InviteVendorToEvent::class)($this->event, $this->vendor);
        $this->puesto = outletFor($this->event, 'Puesto', OperatingUnitKind::Kitchen, $this->vendor);
    });

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/insumos", [
            'name' => 'Carne', 'base_unit' => 'g', 'cost' => '0.45',
        ])
        ->assertRedirect();

    $insumo = InventoryItem::query()->withoutGlobalScopes()->where('name', 'Carne')->sole();
    expect($insumo->vendor_id)->toBe($this->vendor->id);

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/compras", [
            'operating_unit_id' => $this->puesto->id,
            'inventory_item_id' => $insumo->id,
            'quantity' => '5000',
            'unit_cost' => '0.45',
        ])
        ->assertRedirect();

    $nivel = StockLevel::query()->withoutGlobalScopes()
        ->where('operating_unit_id', $this->puesto->id)
        ->where('inventory_item_id', $insumo->id)->sole();
    expect((float) $nivel->quantity)->toBe(5000.0);
});

it('still keeps vendor staff away from the owner operations', function (): void {
    $staff = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro2@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    $this->actingAs($staff)
        ->post("/panel/comercios/{$this->vendor->id}/productos", ['name' => 'X', 'price' => '1', 'category_id' => 1])
        ->assertForbidden();
});

it('links a simple product to an item and builds a recipe from the profile', function (): void {
    // Insumos del comercio
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/insumos", ['name' => 'Presidente (unidad)', 'base_unit' => 'unidad'])
        ->assertRedirect();
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/insumos", ['name' => 'Ron', 'base_unit' => 'ml'])
        ->assertRedirect();

    $cerveza = InventoryItem::query()->withoutGlobalScopes()->where('name', 'Presidente (unidad)')->sole();
    $ron = InventoryItem::query()->withoutGlobalScopes()->where('name', 'Ron')->sole();

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/categorias", ['name' => 'Bar', 'tipo' => 'bebidas'])
        ->assertRedirect();
    $categoria = Category::query()->withoutGlobalScopes()->where('name', 'Bar')->sole();

    // Simple VINCULADO: vende 1, descuenta 1.
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos", [
            'name' => 'Presidente', 'price' => '350', 'category_id' => $categoria->id,
            'kind' => 'simple', 'inventory_item_id' => $cerveza->id,
        ])
        ->assertRedirect();

    $simple = Product::query()->withoutGlobalScopes()->where('name', 'Presidente')->sole();
    expect($simple->track_stock)->toBeTrue()
        ->and($simple->inventory_item_id)->toBe($cerveza->id);

    // Con RECETA: se crea y se arma el escandallo desde el perfil.
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos", [
            'name' => 'Cuba Libre', 'price' => '400', 'category_id' => $categoria->id, 'kind' => 'receta',
        ])
        ->assertRedirect();

    $receta = Product::query()->withoutGlobalScopes()->where('name', 'Cuba Libre')->sole();
    expect($receta->type)->toBe(ProductType::Recipe);

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos/{$receta->id}/receta", [
            'inventory_item_id' => $ron->id, 'quantity' => '60',
        ])
        ->assertRedirect();

    $ingrediente = $receta->recipeItems()->withoutGlobalScopes()->sole();
    expect((float) $ingrediente->quantity)->toBe(60.0);

    // Y se puede quitar.
    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos/{$receta->id}/receta/{$ingrediente->id}/eliminar")
        ->assertRedirect();

    expect($receta->recipeItems()->withoutGlobalScopes()->count())->toBe(0);
});

it('creates an itbis exempt product and re-taxes it from its row', function (): void {
    $categoria = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => Category::create(['name' => 'Refrescos', 'dispatch' => DispatchArea::Bar]),
    ));

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos", [
            'name' => 'Agua', 'price' => '50', 'category_id' => $categoria->id,
            'kind' => 'simple', 'itbis' => 'exento',
        ])
        ->assertRedirect();

    $agua = Product::query()->withoutGlobalScopes()->where('name', 'Agua')->sole();
    expect($agua->itbis_exempt)->toBeTrue();

    // Y el perfil lo delata y permite volver a gravarlo desde su fila.
    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$this->vendor->id}")
        ->assertOk()
        ->assertSee('Exento de ITBIS');

    $this->actingAs($this->owner)
        ->post("/panel/comercios/{$this->vendor->id}/productos/{$agua->id}", ['itbis_exempt' => 0])
        ->assertRedirect();

    expect(Product::query()->withoutGlobalScopes()->findOrFail($agua->id)->itbis_exempt)->toBeFalse();
});
