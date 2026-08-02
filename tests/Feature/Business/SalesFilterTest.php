<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\TenantContext;

/**
 * El día del negocio empieza a medianoche EN RD, no en UTC. Sin esto, la
 * franja de más venta de un bar —de las ocho a las doce— se le atribuiría al
 * día siguiente, y el resumen de portada contradiría a este listado sobre el
 * mismo día.
 */
beforeEach(function (): void {
    $this->negocio = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->negocio, function (): void {
        $this->sucursal = app(CreateBranch::class)('Sucursal Centro');
        $categoria = Category::query()->create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);
        $this->producto = Product::query()->create([
            'category_id' => $categoria->id, 'name' => 'Presidente',
            'type' => ProductType::Simple, 'price_cents' => 20000, 'itbis_exempt' => true,
        ]);
        $this->caja = app(OpenCashSession::class)($this->sucursal, null, 0);
    });

    $this->dueno = app(CreateTenantUser::class)(
        $this->negocio, 'Juan', 'juan@bar.test', 'Secreta-2026', Role::Owner,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/**
 * Cobra una venta EN ese instante. Se viaja en el tiempo en vez de retocar
 * `paid_at` después porque el dominio no deja editar una orden cobrada —
 * justo la garantía que no queremos rodear ni siquiera en una prueba.
 */
function ventaA(string $instanteUtc): void
{
    test()->travelTo($instanteUtc);

    app(TenantContext::class)->runAs(test()->negocio, function () use ($instanteUtc): void {
        $orden = app(PlaceOrder::class)(
            test()->caja,
            [['product_id' => test()->producto->id, 'quantity' => 1]],
            'pos-'.substr(md5($instanteUtc), 0, 8),
        );
        app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
    });

    test()->travelBack();
}

it('counts a nine at night sale on its own local day, not the next one', function (): void {
    // 2 de agosto 01:00 UTC = 1 de agosto, 21:00 en RD.
    ventaA('2026-08-02 01:00:00');

    $delDosDeAgosto = $this->actingAs($this->dueno)
        ->get('/business/ventas?desde=2026-08-02&hasta=2026-08-02')
        ->assertOk()
        ->viewData('resumen');

    expect($delDosDeAgosto->cobrado)->toBe(0);

    $delPrimero = $this->actingAs($this->dueno)
        ->get('/business/ventas?desde=2026-08-01&hasta=2026-08-01')
        ->viewData('resumen');

    expect($delPrimero->cobrado)->toBe(20000);
});

it('includes the busiest hours of the last day of the range', function (): void {
    // 3 de agosto 01:00 UTC = 2 de agosto, 21:00 en RD.
    ventaA('2026-08-03 01:00:00');

    $resumen = $this->actingAs($this->dueno)
        ->get('/business/ventas?desde=2026-08-02&hasta=2026-08-02')
        ->assertOk()
        ->viewData('resumen');

    expect($resumen->cobrado)->toBe(20000);
});

it('never repaints the end date shifted by a day', function (): void {
    // Carbon es mutable: calcular el límite abierto sobre el propio objeto
    // devolvía a la vista un día de más, y el rango crecía en cada reenvío.
    $respuesta = $this->actingAs($this->dueno)
        ->get('/business/ventas?desde=2026-08-01&hasta=2026-08-02')
        ->assertOk();

    expect($respuesta->viewData('hasta'))->toBe('2026-08-02')
        ->and($respuesta->viewData('desde'))->toBe('2026-08-01');
});

it('rejects a malformed date instead of crashing', function (): void {
    $this->actingAs($this->dueno)
        ->from('/business/ventas')
        ->get('/business/ventas?desde=no-es-una-fecha')
        ->assertSessionHasErrors('desde');

    $this->actingAs($this->dueno)
        ->from('/business/ventas')
        ->get('/business/ventas?desde=2026-08-10&hasta=2026-08-01')
        ->assertSessionHasErrors('hasta');
});
