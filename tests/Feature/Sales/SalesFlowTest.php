<?php

declare(strict_types=1);

use App\Domains\Business\Actions\CreateBranch;
use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Sales\Actions\CloseCashSession;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Actions\VoidOrder;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;

/**
 * El flujo completo de una venta: caja abierta, orden con instantáneas,
 * cobro que descuenta inventario por el escandallo, y cierre contra lo
 * contado. Dinero en centavos; el ITBIS va incluido en el precio.
 */
beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto');

    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $this->branch = app(CreateBranch::class)('Sucursal Centro');

        $categoria = Category::create(['name' => 'Cócteles', 'dispatch' => DispatchArea::Bar]);
        $this->ron = InventoryItem::create(['name' => 'Ron blanco', 'base_unit' => MeasurementUnit::Milliliter, 'cost_cents' => 0]);
        $cerveza = InventoryItem::create(['name' => 'Presidente (unidad)', 'base_unit' => MeasurementUnit::Unit, 'cost_cents' => 0]);

        $this->mojito = Product::create([
            'category_id' => $categoria->id, 'name' => 'Mojito',
            'type' => ProductType::Recipe, 'price_cents' => 40000,
        ]);
        $this->mojito->recipeItems()->create(['inventory_item_id' => $this->ron->id, 'quantity' => 60]);

        $this->presidente = Product::create([
            'category_id' => $categoria->id, 'name' => 'Presidente',
            'type' => ProductType::Simple, 'price_cents' => 30000,
            'track_stock' => true, 'inventory_item_id' => $cerveza->id,
        ]);

        app(RegisterPurchase::class)($this->branch, $this->ron, 1000, 80, 'Inicial');
        app(RegisterPurchase::class)($this->branch, $cerveza, 24, 9000, 'Inicial');

        $this->caja = app(OpenCashSession::class)($this->branch, null, 500000);
    });
});

afterEach(fn () => app(TenantContext::class)->clear());

it('opens a single session per unit', function (): void {
    app(TenantContext::class)->runAs(
        $this->tenant,
        fn () => app(OpenCashSession::class)($this->branch, null, 100000),
    );
})->throws(SalesException::class);

it('places an order with frozen names and prices and the itbis breakdown', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)(
            $this->caja,
            [
                ['product_id' => $this->mojito->id, 'quantity' => 2],
                ['product_id' => $this->presidente->id, 'quantity' => 1],
            ],
            'pos-0001',
        );

        // 2×400.00 + 300.00 = 1,100.00; ITBIS incluido = 1100×18/118.
        expect($order->subtotal_cents)->toBe(110000)
            ->and($order->itbis_cents)->toBe((int) round(110000 * 18 / 118))
            ->and($order->total_cents)->toBe(110000)
            ->and($order->lines)->toHaveCount(2)
            ->and($order->lines->firstWhere('product_name', 'Mojito')->unit_price_cents)->toBe(40000);
    });
});

it('is idempotent by client_ref: resending returns the same order', function (): void {
    [$a, $b] = app(TenantContext::class)->runAs($this->tenant, fn (): array => [
        app(PlaceOrder::class)($this->caja, [['product_id' => $this->presidente->id, 'quantity' => 1]], 'pos-0002'),
        app(PlaceOrder::class)($this->caja, [['product_id' => $this->presidente->id, 'quantity' => 5]], 'pos-0002'),
    ]);

    expect($b->id)->toBe($a->id)
        ->and(Order::query()->withoutGlobalScopes()->where('client_ref', 'pos-0002')->count())->toBe(1);
});

it('charges the order and consumes stock through the recipe', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->mojito->id, 'quantity' => 3],
        ], 'pos-0003', null, true);

        // Propina legal 10 %: 3×400 = 1,200.00 + 120.00.
        expect($order->tip_cents)->toBe(12000)
            ->and($order->total_cents)->toBe(132000);

        app(PayOrder::class)($order, PaymentMethod::Cash, 132000);

        $ron = StockLevel::query()
            ->where('operating_unit_id', $this->branch->id)
            ->where('inventory_item_id', $this->ron->id)
            ->sole();

        // 1000 ml comprados - 3 mojitos × 60 ml.
        expect((float) $ron->quantity)->toBe(820.0)
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});

it('never blocks a sale for lack of stock: it goes negative', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 30],
        ], 'pos-0004');

        app(PayOrder::class)($order, PaymentMethod::Card, $order->total_cents);

        $nivel = StockLevel::query()
            ->where('operating_unit_id', $this->branch->id)
            ->whereRelation('inventoryItem', 'name', 'Presidente (unidad)')
            ->sole();

        expect((float) $nivel->quantity)->toBe(-6.0);
    });
});

it('rejects a payment below the total and keeps history frozen', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0005');

        expect(fn () => app(PayOrder::class)($order, PaymentMethod::Cash, 100))
            ->toThrow(SalesException::class);

        app(PayOrder::class)($order, PaymentMethod::Cash, 30000);

        // Cobrada es historia: ni editarla ni volver a cobrarla.
        expect(fn () => $order->fresh()->update(['subtotal_cents' => 1]))->toThrow(SalesException::class)
            ->and(fn () => app(PayOrder::class)($order->fresh(), PaymentMethod::Cash, 30000))
            ->toThrow(SalesException::class);
    });
});

it('voids an open order without touching stock', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->mojito->id, 'quantity' => 1],
        ], 'pos-0006');

        app(VoidOrder::class)($order, 'Cliente se fue');

        $ron = StockLevel::query()
            ->where('operating_unit_id', $this->branch->id)
            ->where('inventory_item_id', $this->ron->id)
            ->sole();

        expect($order->fresh()->status)->toBe(OrderStatus::Void)
            ->and((float) $ron->quantity)->toBe(1000.0);
    });
});

it('closes the till against the counted cash, cash payments only', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $efectivo = app(PlaceOrder::class)($this->caja, [['product_id' => $this->presidente->id, 'quantity' => 2]], 'pos-0007');
        app(PayOrder::class)($efectivo, PaymentMethod::Cash, 60000);

        $tarjeta = app(PlaceOrder::class)($this->caja, [['product_id' => $this->presidente->id, 'quantity' => 1]], 'pos-0008');
        app(PayOrder::class)($tarjeta, PaymentMethod::Card, 30000);

        // Fondo 5,000.00 + 600.00 en efectivo; contado con 50.00 de faltante.
        $cerrada = app(CloseCashSession::class)($this->caja, 555000);

        expect($cerrada->expected_cents)->toBe(560000)
            ->and($cerrada->difference_cents)->toBe(-5000);

        expect(fn () => app(PlaceOrder::class)($this->caja->fresh(), [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0009'))->toThrow(SalesException::class);
    });
});
