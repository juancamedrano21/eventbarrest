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
 * El detalle de una venta en el panel (patrón order-details): desglose
 * fiscal por línea, pago, comisión congelada — y las fronteras de siempre.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000); // 10 %
        $puesto = outletFor($this->event, 'Puesto', OperatingUnitKind::Kitchen, $this->vendor);

        $this->sale = app(VendorContext::class)->runAs($this->vendor, function () use ($puesto) {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $taco = Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 25000]);
            $agua = Product::create(['category_id' => $cat->id, 'name' => 'Agua', 'type' => ProductType::Simple, 'price_cents' => 10000, 'itbis_exempt' => true]);
            $caja = app(OpenCashSession::class)($puesto, null, 0);

            $orden = app(PlaceOrder::class)($caja, [
                ['product_id' => $taco->id, 'quantity' => 4],
                ['product_id' => $agua->id, 'quantity' => 1],
            ], 'venta-detalle-001', null, true);

            // Subtotal 110,000; ITBIS solo del taco: round(100000×18/118) =
            // 15,254; propina round((110000-15254)×0.10) = 9,475 → 119,475.
            return app(PayOrder::class)($orden, PaymentMethod::Cash, 120000);
        });
    });

    $this->owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('shows the full sale detail with the frozen fiscal breakdown and commission', function (): void {
    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$this->vendor->id}/ventas/{$this->sale->id}")
        ->assertOk()
        ->assertSee('Detalle de la venta')
        ->assertSee('Cobrada')
        ->assertSee('Taco')
        ->assertSee('Agua')
        ->assertSee('Exenta')                  // la línea del agua no grava
        ->assertSee('152.54')                  // ITBIS solo de lo gravado
        ->assertSee('Propina legal')
        ->assertSee('94.75')
        ->assertSee('1,194.75')                // total
        ->assertSee('Efectivo')
        ->assertSee('5.25')                    // vuelto de 1,200.00
        ->assertSee('Tu comisión (10 %)')
        ->assertSee('Bocao 2026')
        ->assertSee('Puesto');
});

it('links the sales tab to each sale detail', function (): void {
    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$this->vendor->id}")
        ->assertOk()
        ->assertSee("ventas/{$this->sale->id}");
});

it('hides sales from other vendors and other accounts', function (): void {
    $ajeno = app(TenantContext::class)->runAs($this->organizer, fn () => app(CreateVendor::class)('Otro Comercio'));

    // El comercio equivocado: la venta no existe para él.
    $this->actingAs($this->owner)
        ->get("/panel/comercios/{$ajeno->id}/ventas/{$this->sale->id}")
        ->assertNotFound();
});

it('never shows a sale to another account', function (): void {
    $otra = app(CreateTenant::class)('Rio Fest', null, TenantType::Organizer);
    $otroVendor = app(TenantContext::class)->runAs($otra, fn () => app(CreateVendor::class)('Tacos'));
    $ownerAjena = app(CreateTenantUser::class)($otra, 'Bea', 'bea@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($ownerAjena)
        ->get("/panel/comercios/{$otroVendor->id}/ventas/{$this->sale->id}")
        ->assertNotFound();
});

it('keeps vendor staff out of the sale detail', function (): void {
    $staff = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );

    $this->actingAs($staff)
        ->get("/panel/comercios/{$this->vendor->id}/ventas/{$this->sale->id}")
        ->assertForbidden();
});
