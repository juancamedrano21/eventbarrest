<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;

/**
 * La puerta /comercio (ADR-007): el panel privado del personal del
 * comercio. Su comercio es implícito; cada audiencia rebota a su puerta;
 * las operaciones son las MISMAS compartidas con /panel.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);
        $this->puesto = app(CreateEventOutlet::class)($this->event, $this->vendor, 'Puesto', OperatingUnitKind::Kitchen);
    });

    $this->encargada = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('opens the vendor home with its own menu, sales and inventory', function (): void {
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs($this->vendor, function (): void {
        $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
        Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 25000]);
    }));

    $this->actingAs($this->encargada)
        ->get('/event-vendor')
        ->assertOk()
        ->assertSee('Mi comercio')
        ->assertSee('Tacos del Puerto')
        ->assertSee('Taco')
        ->assertSee('Ventas de hoy')
        ->assertSee('Registrar compra');
});

it('bounces every audience to its own door', function (): void {
    // Usuario de cuenta → /panel.
    $owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
    $this->actingAs($owner)->get('/event-vendor')->assertRedirect('/event-panel');

    // Cajera pura (su trabajo entero es el POS) → la caja de SU mundo.
    // /pos la rechazaría por modalidad: es la del bar independiente.
    $cajera = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::Cashier, $this->vendor,
    );
    $this->actingAs($cajera)->get('/event-vendor')->assertRedirect('/event-pos');
});

it('cuts access when the vendor is suspended', function (): void {
    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => $this->vendor->update(['status' => VendorStatus::Suspended]),
    );

    $this->actingAs($this->encargada)->get('/event-vendor')->assertForbidden();
});

it('manages its own catalog and inventory through the shared operations', function (): void {
    $categoria = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]),
    ));

    // Producto exento desde SU puerta, sin vendor en la URL.
    $this->actingAs($this->encargada)
        ->post('/event-vendor/productos', [
            'name' => 'Agua', 'price' => '50', 'category_id' => $categoria->id,
            'kind' => 'simple', 'itbis' => 'exento',
        ])
        ->assertRedirect();

    $agua = Product::query()->withoutGlobalScopes()->where('name', 'Agua')->sole();
    expect($agua->vendor_id)->toBe($this->vendor->id)
        ->and($agua->itbis_exempt)->toBeTrue();

    // Insumo y compra: el stock del puesto la refleja.
    $this->actingAs($this->encargada)
        ->post('/event-vendor/insumos', ['name' => 'Botella de agua', 'base_unit' => 'unidad', 'cost' => '20'])
        ->assertRedirect();

    $botella = InventoryItem::query()->withoutGlobalScopes()->where('name', 'Botella de agua')->sole();

    $this->actingAs($this->encargada)
        ->post('/event-vendor/compras', [
            'operating_unit_id' => $this->puesto->id,
            'inventory_item_id' => $botella->id,
            'quantity' => '24', 'unit_cost' => '18',
        ])
        ->assertRedirect();

    $this->actingAs($this->encargada)
        ->get('/event-vendor')
        ->assertOk()
        ->assertSee('Botella de agua');
});

it('shows its own sale detail and 404s a foreign one', function (): void {
    $venta = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs($this->vendor, function () {
        $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
        $taco = Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 25000]);
        $caja = app(OpenCashSession::class)($this->puesto, null, 0);
        $orden = app(PlaceOrder::class)($caja, [['product_id' => $taco->id, 'quantity' => 2]], 'comercio-001');

        return app(PayOrder::class)($orden, PaymentMethod::Cash, 50000);
    }));

    // Una venta de OTRO comercio de la misma cuenta, creada ANTES de tocar
    // HTTP: el middleware fija contextos y contaminaría la escritura.
    $ajena = app(TenantContext::class)->runAs($this->organizer, function () {
        $otro = app(CreateVendor::class)('Otro Comercio');
        app(InviteVendorToEvent::class)($this->event, $otro, 500);
        $puesto = app(CreateEventOutlet::class)($this->event, $otro, 'Puesto B', OperatingUnitKind::Bar);

        return app(VendorContext::class)->runAs($otro, function () use ($puesto) {
            $cat = Category::create(['name' => 'Bar', 'dispatch' => DispatchArea::Bar]);
            $ron = Product::create(['category_id' => $cat->id, 'name' => 'Ron', 'type' => ProductType::Simple, 'price_cents' => 30000]);
            $caja = app(OpenCashSession::class)($puesto, null, 0);
            $orden = app(PlaceOrder::class)($caja, [['product_id' => $ron->id, 'quantity' => 1]], 'comercio-002');

            return app(PayOrder::class)($orden, PaymentMethod::Cash, 30000);
        });
    });

    $this->actingAs($this->encargada)
        ->get("/event-vendor/ventas/{$venta->id}")
        ->assertOk()
        ->assertSee('P0001')
        ->assertSee('500.00');

    $this->actingAs($this->encargada)
        ->get("/event-vendor/ventas/{$ajena->id}")
        ->assertNotFound();
});

it('lets warehouse manage inventory but never see the money', function (): void {
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs($this->vendor, function (): void {
        $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
        $taco = Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 25000]);
        $caja = app(OpenCashSession::class)($this->puesto, null, 0);
        $orden = app(PlaceOrder::class)($caja, [['product_id' => $taco->id, 'quantity' => 2]], 'almacen-001');
        app(PayOrder::class)($orden, PaymentMethod::Cash, 50000);
    }));

    $almacen = app(CreateTenantUser::class)(
        $this->organizer, 'Wil', 'wil@x.test', 'Secreta-2026', Role::Warehouse, $this->vendor,
    );

    // Entra (gestiona inventario), pero el dinero no existe para su rol:
    // ni KPIs, ni pestaña Ventas, ni acciones de catálogo.
    $this->actingAs($almacen)
        ->get('/event-vendor')
        ->assertOk()
        ->assertSee('Registrar compra')
        ->assertDontSee('Ventas de hoy')
        ->assertDontSee('500.00')
        ->assertDontSee('Ver detalle')
        ->assertDontSee('Nuevo producto');

    // Y el alta de insumos es inventario, no carta: Almacén puede.
    $this->actingAs($almacen)
        ->post('/event-vendor/insumos', ['name' => 'Cajas de tomate', 'base_unit' => 'unidad'])
        ->assertRedirect();

    expect(InventoryItem::query()->withoutGlobalScopes()->where('name', 'Cajas de tomate')->exists())->toBeTrue();

    // El detalle de la venta: 403, coherente con lo que el home le oculta.
    $venta = Order::query()->withoutGlobalScopes()->where('client_ref', 'almacen-001')->sole();
    $this->actingAs($almacen)->get("/event-vendor/ventas/{$venta->id}")->assertForbidden();
});

it('lets staff of a draft vendor in: only suspension cuts access', function (): void {
    app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => $this->vendor->update(['status' => VendorStatus::Draft]),
    );

    $this->actingAs($this->encargada)->get('/event-vendor')->assertOk();
});
