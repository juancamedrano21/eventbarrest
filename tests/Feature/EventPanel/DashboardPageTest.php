<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Actions\RefundOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * El dashboard con números reales: ventas, cajas, desglose por comercio y
 * la comisión del organizador por evento — congelada al vender, cortada en
 * el día de RD y solo para quien tiene reportes de la cuenta.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/**
 * Un festival con un comercio al 10 % y una venta cobrada de RD$1,000.
 *
 * @return array{0: Tenant, 1: Event, 2: Vendor}
 */
function festivalConVentaDeMil(): array
{
    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);

    [$event, $vendor] = app(TenantContext::class)->runAs($organizer, function (): array {
        $event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($event, $vendor, 1000); // 10 %
        $puesto = outletFor($event, 'Puesto', OperatingUnitKind::Kitchen, $vendor);

        app(VendorContext::class)->runAs($vendor, function () use ($puesto): void {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $taco = Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 25000]);
            $caja = app(OpenCashSession::class)($puesto, null, 0);
            $orden = app(PlaceOrder::class)($caja, [['product_id' => $taco->id, 'quantity' => 4]], 'dash-001');
            app(PayOrder::class)($orden, PaymentMethod::Cash, 100000); // RD$1,000
        });

        return [$event, $vendor];
    });

    return [$organizer, $event, $vendor];
}

it('shows real sales and the organizer commission per event', function (): void {
    [$organizer] = festivalConVentaDeMil();

    $owner = app(CreateTenantUser::class)($organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($owner)
        ->get('/event-panel')
        ->assertOk()
        ->assertSee('Ventas de hoy')
        ->assertSee('1,000.00')            // vendido
        ->assertSee('Tacos del Puerto')    // por comercio
        ->assertSee('Tu comisión por evento')
        ->assertSee('100.00');             // 10 % de comisión
});

it('freezes the commission at sale time: renegotiating or removing the vendor never rewrites it', function (): void {
    [$organizer, $event, $vendor] = festivalConVentaDeMil();

    // Renegocia al 5 % y hasta borra la participación del pivote: lo que
    // se cobró al 10 % sigue reportándose al 10 %.
    app(TenantContext::class)->runAs($organizer, function () use ($event, $vendor): void {
        app(InviteVendorToEvent::class)($event, $vendor, 500);
    });
    DB::table('event_vendor')->where('event_id', $event->id)->where('vendor_id', $vendor->id)->delete();

    $owner = app(CreateTenantUser::class)($organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($owner)
        ->get('/event-panel')
        ->assertOk()
        ->assertSee('Bocao 2026')
        ->assertSee('100.00');
});

it('hides the account money from roles without account reports', function (): void {
    [$organizer] = festivalConVentaDeMil();

    $gerente = app(CreateTenantUser::class)($organizer, 'Gio', 'gio@x.test', 'Secreta-2026', Role::EventManager);

    $this->actingAs($gerente)
        ->get('/event-panel')
        ->assertOk()
        ->assertDontSee('Ventas de hoy')
        ->assertDontSee('Tu comisión por evento')
        ->assertDontSee('1,000.00')
        ->assertSee('reportes de la cuenta');
});

it('never shows another account its sales', function (): void {
    festivalConVentaDeMil();

    $ajena = app(CreateTenant::class)('Rio Fest', null, TenantType::Organizer);
    $owner = app(CreateTenantUser::class)($ajena, 'Bea', 'bea@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($owner)
        ->get('/event-panel')
        ->assertOk()
        ->assertDontSee('Tacos del Puerto')
        ->assertDontSee('1,000.00');
});

it('redirects vendor staff to their own door', function (): void {
    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    $vendor = app(TenantContext::class)->runAs($organizer, fn () => app(CreateVendor::class)('Tacos'));
    $staff = app(CreateTenantUser::class)($organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $vendor);

    $this->actingAs($staff)->get('/event-panel')->assertRedirect('/event-vendor');
});

it('never counts refunded money as sales nor charges commission on it', function (): void {
    [$organizer, , $vendor] = festivalConVentaDeMil();

    // Se devuelve la MITAD de la venta de RD$1,000.
    app(TenantContext::class)->runAs($organizer, fn () => app(VendorContext::class)->runAs($vendor, function (): void {
        $orden = Order::query()->where('client_ref', 'dash-001')->sole();
        $caja = CashSession::query()->sole();

        app(RefundOrder::class)($orden, $caja, 50000, 'Cliente devolvió la mitad');
    }));

    $owner = app(CreateTenantUser::class)($organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($owner)
        ->get('/event-panel')
        ->assertOk()
        // Ventas netas: 1,000 − 500. Y la comisión sobre lo que quedó,
        // no sobre lo devuelto: 10 % de 500, no de 1,000.
        ->assertSee('500.00')
        ->assertSee('50.00')
        ->assertDontSee('1,000.00')
        ->assertDontSee('100.00');
});
