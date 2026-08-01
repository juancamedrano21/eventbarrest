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
use App\Domains\Identity\Enums\Role;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\TenantContext;

/**
 * El dashboard con números reales: ventas, cajas, desglose por comercio y
 * la comisión del organizador por evento — el reporte que faltaba.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('shows real sales and the organizer commission per event', function (): void {
    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($organizer, function (): void {
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
    });

    $owner = app(CreateTenantUser::class)($organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($owner)
        ->get('/panel')
        ->assertOk()
        ->assertSee('Ventas de hoy')
        ->assertSee('1,000.00')            // vendido
        ->assertSee('Tacos del Puerto')    // por comercio
        ->assertSee('Tu comisión por evento')
        ->assertSee('100.00');             // 10 % de comisión
});

it('redirects vendor staff to their own world', function (): void {
    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    $vendor = app(TenantContext::class)->runAs($organizer, fn () => app(CreateVendor::class)('Tacos'));
    $staff = app(CreateTenantUser::class)($organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $vendor);

    $this->actingAs($staff)->get('/panel')->assertRedirect('/app');
});
