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
