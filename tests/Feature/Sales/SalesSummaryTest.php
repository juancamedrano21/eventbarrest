<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Actions\RefundOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Queries\SalesSummary;
use App\Domains\Tenancy\TenantContext;

/**
 * La propina legal viaja dentro de total_cents. Estas pruebas fijan que el
 * negocio no se atribuya ese dinero, y que un reembolso no lo descuente dos
 * veces — el error que daría «ventas» negativas.
 */
beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);

    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $this->branch = app(CreateBranch::class)('Sucursal Centro');

        $categoria = Category::query()->create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);
        $this->producto = Product::query()->create([
            'category_id' => $categoria->id,
            'name' => 'Presidente',
            'type' => ProductType::Simple,
            'price_cents' => 20000,
            'track_stock' => false,
            'active' => true,
            'itbis_exempt' => true,
        ]);

        $this->session = app(OpenCashSession::class)($this->branch, null, 0);
    });

    $this->ref = 0;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** Cobra en efectivo una unidad del producto, con o sin propina. */
function cobrar(bool $conPropina): object
{
    return app(TenantContext::class)->runAs(test()->tenant, function () use ($conPropina) {
        $order = app(PlaceOrder::class)(
            test()->session,
            [['product_id' => test()->producto->id, 'quantity' => 1]],
            'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
            null,
            $conPropina,
        );

        return app(PayOrder::class)($order, PaymentMethod::Cash, $order->total_cents);
    });
}

it('keeps the legal tip out of what counts as the business sales', function (): void {
    $order = cobrar(conPropina: true);

    // ITBIS exento: 200.00 de base, 20.00 de propina, 220.00 cobrado.
    expect($order->total_cents)->toBe(22000)
        ->and($order->tip_cents)->toBe(2000);

    $resumen = app(TenantContext::class)->runAs(
        $this->tenant,
        fn () => app(SalesSummary::class)->forRange(),
    );

    expect($resumen->cobrado)->toBe(22000)
        ->and($resumen->propina)->toBe(2000)
        ->and($resumen->ventas)->toBe(20000)
        ->and($resumen->devuelto)->toBe(0);
});

it('never counts a refunded tip twice', function (): void {
    $order = cobrar(conPropina: true);

    app(TenantContext::class)->runAs($this->tenant, function () use ($order): void {
        app(RefundOrder::class)($order, $this->session, $order->total_cents, 'Cliente insatisfecho');
    });

    $resumen = app(TenantContext::class)->runAs(
        $this->tenant,
        fn () => app(SalesSummary::class)->forRange(),
    );

    // Se devolvió todo: no queda ni venta ni propina. Restar la propina
    // entera además del reembolso daría -2000, dinero que nunca existió.
    expect($resumen->devuelto)->toBe(22000)
        ->and($resumen->propina)->toBe(0)
        ->and($resumen->ventas)->toBe(0);
});

it('splits a partial refund between sales and tip in proportion', function (): void {
    $order = cobrar(conPropina: true);

    app(TenantContext::class)->runAs($this->tenant, function () use ($order): void {
        app(RefundOrder::class)($order, $this->session, 11000, 'Devolución de la mitad');
    });

    $resumen = app(TenantContext::class)->runAs(
        $this->tenant,
        fn () => app(SalesSummary::class)->forRange(),
    );

    // La mitad devuelta se lleva la mitad de la propina: quedan 100.00 de
    // venta y 10.00 de propina.
    expect($resumen->devuelto)->toBe(11000)
        ->and($resumen->propina)->toBe(1000)
        ->and($resumen->ventas)->toBe(10000);
});

it('always keeps sales plus tip plus refunded equal to what was charged', function (): void {
    cobrar(conPropina: true);
    cobrar(conPropina: false);
    $tercera = cobrar(conPropina: true);

    app(TenantContext::class)->runAs($this->tenant, function () use ($tercera): void {
        app(RefundOrder::class)($tercera, $this->session, 7333, 'Parcial con céntimos');
    });

    $resumen = app(TenantContext::class)->runAs(
        $this->tenant,
        fn () => app(SalesSummary::class)->forRange(),
    );

    expect($resumen->ventas + $resumen->propina + $resumen->devuelto)->toBe($resumen->cobrado)
        ->and($resumen->transacciones)->toBe(3);
});

it('reports the same money broken down by day and by branch', function (): void {
    cobrar(conPropina: true);
    cobrar(conPropina: false);

    [$total, $porDia, $porSucursal] = app(TenantContext::class)->runAs($this->tenant, fn (): array => [
        app(SalesSummary::class)->forRange(),
        app(SalesSummary::class)->byDay((string) today(config('app.business_timezone'))->subDays(13)->utc()),
        app(SalesSummary::class)->byUnit(),
    ]);

    expect($porDia->sum(fn (object $d): int => $d->ventas))->toBe($total->ventas)
        ->and($porDia->sum(fn (object $d): int => $d->propina))->toBe($total->propina)
        ->and($porSucursal)->toHaveCount(1)
        ->and($porSucursal->first()->nombre)->toBe('Sucursal Centro')
        ->and($porSucursal->first()->ventas)->toBe($total->ventas);
});
