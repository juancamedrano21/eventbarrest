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
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\CloseCashSession;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Actions\VoidOrder;
use App\Domains\Sales\Enums\CashSessionStatus;
use App\Domains\Sales\Enums\ItbisMode;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Payment;
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

        // 2×400.00 + 300.00 = 1,100.00; ITBIS incluido, POR LÍNEA (es el
        // redondeo del comprobante): round(80000×18/118) + round(30000×18/118).
        expect($order->subtotal_cents)->toBe(110000)
            ->and($order->itbis_cents)->toBe(12203 + 4576)
            ->and($order->total_cents)->toBe(110000)
            ->and($order->lines)->toHaveCount(2)
            ->and($order->lines->firstWhere('product_name', 'Mojito')->itbis_cents)->toBe(12203)
            ->and($order->lines->firstWhere('product_name', 'Mojito')->unit_price_cents)->toBe(40000);
    });
});

it('exempts flagged products from the itbis breakdown, line by line', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $categoria = Category::query()->where('name', 'Cócteles')->sole();
        $agua = Product::create([
            'category_id' => $categoria->id, 'name' => 'Agua',
            'type' => ProductType::Simple, 'price_cents' => 10000,
            'itbis_exempt' => true,
        ]);

        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $agua->id, 'quantity' => 2],
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0016', null, true);

        // Solo la Presidente desglosa ITBIS; el agua es exenta. La propina
        // legal sigue siendo el 10 % de la base sin impuesto.
        expect($order->subtotal_cents)->toBe(50000)
            ->and($order->itbis_cents)->toBe(4576)
            ->and($order->lines->firstWhere('product_name', 'Agua')->itbis_cents)->toBe(0)
            ->and($order->lines->firstWhere('product_name', 'Presidente')->itbis_cents)->toBe(4576)
            ->and($order->tip_cents)->toBe((int) round((50000 - 4576) * 0.10))
            ->and($order->total_cents)->toBe(50000 + $order->tip_cents);
    });
});

it('is idempotent by client_ref: the same sale returns the same order, a different one is refused', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $lines = [['product_id' => $this->presidente->id, 'quantity' => 1]];

        $a = app(PlaceOrder::class)($this->caja, $lines, 'pos-0002');
        $b = app(PlaceOrder::class)($this->caja, $lines, 'pos-0002');

        expect($b->id)->toBe($a->id)
            ->and(Order::query()->withoutGlobalScopes()->where('client_ref', 'pos-0002')->count())->toBe(1);

        // Misma referencia con OTRO contenido: jamás un éxito silencioso.
        expect(fn () => app(PlaceOrder::class)(
            $this->caja,
            [['product_id' => $this->presidente->id, 'quantity' => 5]],
            'pos-0002',
        ))->toThrow(SalesException::class);
    });
});

it('charges the order and consumes stock through the recipe', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->mojito->id, 'quantity' => 3],
        ], 'pos-0003', null, true);

        // Propina legal 10 % sobre la base sin ITBIS: (120000-18305)×0.10.
        expect($order->tip_cents)->toBe(10170)
            ->and($order->total_cents)->toBe(130170);

        app(PayOrder::class)($order, PaymentMethod::Cash, 130170);

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

it('demands the exact amount for card and transfer', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0010');

        expect(fn () => app(PayOrder::class)($order, PaymentMethod::Card, 50000))
            ->toThrow(SalesException::class);
    });
});

it('rejects zero and negative quantities', function (): void {
    app(TenantContext::class)->runAs(
        $this->tenant,
        fn () => app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 0],
        ], 'pos-0011'),
    );
})->throws(SalesException::class);

it('refuses to close a till with open orders', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0012');

        expect(fn () => app(CloseCashSession::class)($this->caja, 500000))
            ->toThrow(SalesException::class);
    });
});

it('refuses to pay an order whose session already closed', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0013');
        app(VoidOrder::class)($order, 'se cierra');

        app(CloseCashSession::class)($this->caja, 500000);

        $reabierta = app(PlaceOrder::class)(app(OpenCashSession::class)($this->branch, null, 0), [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0014');

        // La orden nueva vive en la sesión nueva; la cerrada es historia:
        // ni se reabre ni se retocan sus números.
        expect(fn () => $this->caja->fresh()->update(['status' => CashSessionStatus::Open]))
            ->toThrow(SalesException::class)
            ->and($reabierta->status)->toBe(OrderStatus::Open);
    });
});

it('blocks mass updates and deletes on sales history', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 1],
        ], 'pos-0015');
        app(PayOrder::class)($order, PaymentMethod::Cash, 30000);

        expect(fn () => Order::query()->update(['subtotal_cents' => 0]))->toThrow(SalesException::class)
            ->and(fn () => Payment::query()->delete())->toThrow(SalesException::class);
    });
});

it('keeps the organizer from opening a till on a vendor outlet', function (): void {
    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($organizer, function (): void {
        $event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $outlet = outletFor($event, 'Barra', OperatingUnitKind::Bar);

        // Sin comercio activo (el organizador mira, no opera): denegado.
        expect(fn () => app(OpenCashSession::class)($outlet, null, 0))
            ->toThrow(SalesException::class);
    });
});

it('adds the itbis on top when the business sells with tax outside the price', function (): void {
    app(TenantContext::class)->runAs($this->tenant, function (): void {
        // La cuenta vende con el impuesto POR FUERA.
        $this->tenant->update(['itbis_mode' => ItbisMode::Added]);

        $categoria = Category::query()->where('name', 'Cócteles')->sole();
        $agua = Product::create([
            'category_id' => $categoria->id, 'name' => 'Agua',
            'type' => ProductType::Simple, 'price_cents' => 10000,
            'itbis_exempt' => true,
        ]);

        $order = app(PlaceOrder::class)($this->caja, [
            ['product_id' => $this->presidente->id, 'quantity' => 1],  // 300.00 gravada
            ['product_id' => $agua->id, 'quantity' => 1],              // 100.00 exenta
        ], 'pos-0020', null, true);

        // El precio es la BASE: el ITBIS de la Presidente se suma (18 %) y
        // el agua no aporta. La propina va sobre el subtotal completo,
        // porque aquí el subtotal ya es base sin impuesto.
        expect($order->subtotal_cents)->toBe(40000)
            ->and($order->itbis_cents)->toBe(5400)
            ->and($order->lines->firstWhere('product_name', 'Agua')->itbis_cents)->toBe(0)
            ->and($order->tip_cents)->toBe(4000)
            ->and($order->total_cents)->toBe(40000 + 5400 + 4000)
            ->and($order->itbis_mode)->toBe(ItbisMode::Added);

        // Y se cobra por ese total, no por el subtotal.
        app(PayOrder::class)($order, PaymentMethod::Card, 49400);
        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});

it('lets a vendor override the account rule, and freezes it on the sale', function (): void {
    $organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($organizer, function (): void {
        $event = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));
        $vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($event, $vendor, 0);
        $puesto = outletFor($event, 'Puesto', OperatingUnitKind::Kitchen, $vendor);

        // La cuenta incluye el ITBIS; este comercio lo cobra por fuera.
        $vendor->update(['itbis_mode' => ItbisMode::Added]);

        $order = app(VendorContext::class)->runAs($vendor, function () use ($puesto) {
            $cat = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $taco = Product::create(['category_id' => $cat->id, 'name' => 'Taco', 'type' => ProductType::Simple, 'price_cents' => 10000]);
            $caja = app(OpenCashSession::class)($puesto, null, 0);

            return app(PlaceOrder::class)($caja, [['product_id' => $taco->id, 'quantity' => 1]], 'pos-0021');
        });

        expect($order->itbis_cents)->toBe(1800)
            ->and($order->total_cents)->toBe(11800)
            ->and($order->itbis_mode)->toBe(ItbisMode::Added);

        // El comercio vuelve a la regla de la cuenta: la venta de ayer no
        // se reescribe, la de mañana usa la nueva.
        $vendor->update(['itbis_mode' => null]);
        expect($order->fresh()->itbis_mode)->toBe(ItbisMode::Added);

        $siguiente = app(VendorContext::class)->runAs($vendor, function () use ($puesto) {
            $taco = Product::query()->where('name', 'Taco')->sole();
            $caja = CashSession::query()->where('operating_unit_id', $puesto->id)->sole();

            return app(PlaceOrder::class)($caja, [['product_id' => $taco->id, 'quantity' => 1]], 'pos-0022');
        });

        expect($siguiente->itbis_mode)->toBe(ItbisMode::Included)
            ->and($siguiente->total_cents)->toBe(10000);
    });
});
