<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/**
 * La API del POS de punta a punta: login por capacidad, contexto por token,
 * catálogo del comercio, caja y sincronización idempotente de ventas.
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
            $this->cubaLibre = Product::create([
                'category_id' => $cat->id, 'name' => 'Cuba Libre',
                'type' => ProductType::Recipe, 'price_cents' => 40000,
            ]);
            $this->cubaLibre->recipeItems()->create(['inventory_item_id' => $this->ron->id, 'quantity' => 60]);
            app(RegisterPurchase::class)($this->barra, $this->ron, 1000, 95, 'Inicial');
        });

        $vendors->runAs($this->tacos, function (): void {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            Product::create(['category_id' => $cat->id, 'name' => 'Taco al pastor', 'type' => ProductType::Simple, 'price_cents' => 25000]);
        });
    });

    $this->cajera = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@pos.test', 'Secreta-2026', Role::Cashier, $this->cerveceria,
        username: 'caro',
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('logs a cashier in and refuses whoever cannot operate the pos', function (): void {
    // El POS entra por NOMBRE DE USUARIO, no por correo — y acepta
    // mayúsculas o espacios accidentales del teclado del terminal.
    $ok = $this->postJson('/api/pos/login', [
        'username' => '  Caro ', 'password' => 'Secreta-2026', 'device_name' => 'SUNMI-01',
    ]);
    $ok->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'vendor_id']]);

    $this->postJson('/api/pos/login', [
        'username' => 'caro', 'password' => 'mala', 'device_name' => 'SUNMI-01',
    ])->assertUnprocessable();

    // Almacén no vende ni maneja caja: sin POS — y con el MISMO fallo que
    // una credencial mala, para no enumerar usuarios.
    app(CreateTenantUser::class)($this->organizer, 'Wally', 'wally@pos.test', 'Secreta-2026', Role::Warehouse, $this->cerveceria, username: 'wally');
    $this->postJson('/api/pos/login', [
        'username' => 'wally', 'password' => 'Secreta-2026', 'device_name' => 'SUNMI-01',
    ])->assertUnprocessable();
});

it('bootstraps only the units of the cashiers vendor', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $response = $this->getJson('/api/pos/bootstrap')->assertOk();

    expect(collect($response->json('units'))->pluck('name')->all())->toBe(['Barra'])
        ->and($response->json('permissions'))->toContain('sales.operate');
});

it('serves only the vendors own catalog', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $response = $this->getJson('/api/pos/catalog')->assertOk();

    expect(collect($response->json('products'))->pluck('name')->all())->toBe(['Cuba Libre'])
        ->and(collect($response->json('categories'))->pluck('name')->all())->toBe(['Tragos']);
});

it('opens a till on its own unit and refuses a foreign one', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $this->postJson('/api/pos/sessions', [
        'operating_unit_id' => $this->barra->id, 'opening_cents' => 100000,
    ])->assertCreated();

    // El puesto de Tacos no es suyo: para ella ni existe.
    $this->postJson('/api/pos/sessions', [
        'operating_unit_id' => $this->puesto->id, 'opening_cents' => 0,
    ])->assertNotFound();
});

it('syncs a sale idempotently: one order, one charge, one stock hit', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $sessionId = app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->cerveceria,
        fn () => app(OpenCashSession::class)($this->barra, $this->cajera, 0)->id,
    ));

    $payload = [
        'cash_session_id' => $sessionId,
        'client_ref' => 'sunmi01-000123',
        'lines' => [['product_id' => $this->cubaLibre->id, 'quantity' => 2]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 100000],
    ];

    $first = $this->postJson('/api/pos/orders', $payload)->assertCreated();
    expect($first->json('status'))->toBe('paid')
        ->and($first->json('total_cents'))->toBe(80000);

    // El reenvío offline devuelve la MISMA orden sin duplicar nada.
    $second = $this->postJson('/api/pos/orders', $payload)->assertOk();
    expect($second->json('id'))->toBe($first->json('id'));

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $ron = StockLevel::query()->withoutGlobalScopes()
            ->where('operating_unit_id', $this->barra->id)
            ->where('inventory_item_id', $this->ron->id)
            ->sole();

        // 1000 - 2×60, una sola vez.
        expect((float) $ron->quantity)->toBe(880.0)
            ->and(Order::query()->withoutGlobalScopes()->where('client_ref', 'sunmi01-000123')->count())->toBe(1);
    });
});

it('closes the till through the api', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $sessionId = $this->postJson('/api/pos/sessions', [
        'operating_unit_id' => $this->barra->id, 'opening_cents' => 50000,
    ])->json('id');

    $this->postJson('/api/pos/orders', [
        'cash_session_id' => $sessionId,
        'client_ref' => 'sunmi01-000200',
        'lines' => [['product_id' => $this->cubaLibre->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 40000],
    ])->assertCreated();

    $close = $this->postJson("/api/pos/sessions/{$sessionId}/close", [
        'counted_cents' => 90000,
    ])->assertOk();

    expect($close->json('expected_cents'))->toBe(90000)
        ->and($close->json('difference_cents'))->toBe(0);
});

it('never serves another tenants session through the api', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $otro = app(CreateTenant::class)('Bar Ajeno', null, TenantType::Business);
    $ajena = app(TenantContext::class)->runAs($otro, function () {
        $branch = app(CreateBranch::class)('Central');

        return app(OpenCashSession::class)($branch, null, 0);
    });

    $this->postJson("/api/pos/sessions/{$ajena->id}/close", ['counted_cents' => 0])
        ->assertNotFound();
});

it('keeps organizer staff out of the pos entirely', function (): void {
    $owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@pos.test', 'Secreta-2026', Role::Owner);
    Sanctum::actingAs($owner, ['pos']);

    // El organizador mira desde el panel; el POS es de los comercios.
    $this->getJson('/api/pos/bootstrap')->assertForbidden();
    $this->getJson('/api/pos/catalog')->assertForbidden();
});

it('rejects a reused client_ref with different content instead of lying', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $sessionId = $this->postJson('/api/pos/sessions', [
        'operating_unit_id' => $this->barra->id, 'opening_cents' => 0,
    ])->json('id');

    $base = [
        'cash_session_id' => $sessionId,
        'client_ref' => 'sunmi01-000300',
        'lines' => [['product_id' => $this->cubaLibre->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 40000],
    ];
    $this->postJson('/api/pos/orders', $base)->assertCreated();

    // Misma referencia, contenido distinto: error operable con código.
    $base['lines'] = [['product_id' => $this->cubaLibre->id, 'quantity' => 3]];
    $this->postJson('/api/pos/orders', $base)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'client_ref_reused');
});

it('answers a resync after the till closed with the recorded sale', function (): void {
    Sanctum::actingAs($this->cajera, ['pos']);

    $sessionId = $this->postJson('/api/pos/sessions', [
        'operating_unit_id' => $this->barra->id, 'opening_cents' => 0,
    ])->json('id');

    $payload = [
        'cash_session_id' => $sessionId,
        'client_ref' => 'sunmi01-000400',
        'lines' => [['product_id' => $this->cubaLibre->id, 'quantity' => 1]],
        'payment' => ['method' => 'cash', 'tendered_cents' => 40000],
    ];
    $this->postJson('/api/pos/orders', $payload)->assertCreated();
    $this->postJson("/api/pos/sessions/{$sessionId}/close", ['counted_cents' => 40000])->assertOk();

    // El reenvío tras el cierre devuelve la venta registrada, no un 422.
    $this->postJson('/api/pos/orders', $payload)
        ->assertOk()
        ->assertJsonPath('status', 'paid');
});
