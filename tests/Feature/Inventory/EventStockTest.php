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
use App\Domains\EventManagement\VendorContext;
use App\Domains\Inventory\Actions\AdjustStock;
use App\Domains\Inventory\Actions\AllocateToEvent;
use App\Domains\Inventory\Actions\RegisterWaste;
use App\Domains\Inventory\Actions\ReturnFromEvent;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Queries\EventStockReconciliation;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Tenancy\TenantContext;

/**
 * La mercancía que se le entrega a un puesto de evento y el cuadre del
 * cierre. Es la otra mitad de la liquidación: el dinero por un lado, lo que
 * bajó del camión por el otro.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->event = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now());
        $this->vendor = app(CreateVendor::class)('Cervecería del Malecón');
        app(InviteVendorToEvent::class)($this->event, $this->vendor, 1000);

        $this->barra = app(CreateEventOutlet::class)(
            $this->event, $this->vendor, 'Barra Norte', OperatingUnitKind::Bar,
        );
        $this->bodega = app(CreateEventOutlet::class)(
            $this->event, $this->vendor, 'Bodega', OperatingUnitKind::Mixed,
        );

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $this->cerveza = InventoryItem::create([
                'name' => 'Presidente 350ml', 'base_unit' => 'unidad', 'cost_cents' => 8000,
            ]);
        });
    });

    $this->ref = 0;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Ejecuta algo como el comercio dueño del puesto. */
function comoElComercio(Closure $fn): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs(test()->vendor, $fn),
    );
}

it('records what was handed to the outlet as its own kind of movement', function (): void {
    comoElComercio(fn () => app(AllocateToEvent::class)($this->barra, $this->cerveza, 240));

    $movimiento = comoElComercio(fn () => StockMovement::query()
        ->where('operating_unit_id', $this->barra->id)
        ->sole());

    // No es un traslado: es alguien haciéndose responsable de 240 cervezas
    // para este festival.
    expect($movimiento->type)->toBe(StockMovementType::EventAllocation)
        ->and((float) $movimiento->quantity)->toBe(240.0);

    $nivel = comoElComercio(fn () => StockLevel::query()
        ->where('operating_unit_id', $this->barra->id)
        ->where('inventory_item_id', $this->cerveza->id)
        ->sole());

    expect((float) $nivel->quantity)->toBe(240.0);
});

it('takes it out of the warehouse when it comes from one', function (): void {
    comoElComercio(function (): void {
        // Primero entra en la bodega, y de ahí baja a la barra.
        app(AllocateToEvent::class)($this->bodega, $this->cerveza, 500);
        app(AllocateToEvent::class)($this->barra, $this->cerveza, 240, $this->bodega);
    });

    [$enBodega, $enBarra] = comoElComercio(fn (): array => [
        (float) StockLevel::query()->where('operating_unit_id', $this->bodega->id)->sole()->quantity,
        (float) StockLevel::query()->where('operating_unit_id', $this->barra->id)->sole()->quantity,
    ]);

    expect($enBodega)->toBe(260.0)
        ->and($enBarra)->toBe(240.0);
});

it('never hands stock from one vendor to another vendor outlet', function (): void {
    $ajeno = app(TenantContext::class)->runAs($this->organizer, function () {
        $otro = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->event, $otro, 1200);

        return app(CreateEventOutlet::class)(
            $this->event, $otro, 'Puesto Tacos', OperatingUnitKind::Kitchen,
        );
    });

    comoElComercio(fn () => expect(
        fn () => app(AllocateToEvent::class)($ajeno, $this->cerveza, 10, $this->barra)
    )->toThrow(InventoryException::class));
});

it('refuses a zero or negative amount', function (): void {
    comoElComercio(function (): void {
        expect(fn () => app(AllocateToEvent::class)($this->barra, $this->cerveza, 0))
            ->toThrow(InventoryException::class);
        expect(fn () => app(ReturnFromEvent::class)($this->barra, $this->cerveza, -5))
            ->toThrow(InventoryException::class);
    });
});

it('closes the circle: what was handed, sold, wasted and given back', function (): void {
    $venta = comoElComercio(function () {
        // 240 cervezas a la barra.
        app(AllocateToEvent::class)($this->barra, $this->cerveza, 240);

        // Un producto que descuenta una cerveza por unidad vendida.
        $cat = Category::create(['name' => 'Cervezas', 'dispatch' => DispatchArea::Bar]);
        $producto = Product::create([
            'category_id' => $cat->id, 'name' => 'Presidente',
            'type' => ProductType::Simple, 'price_cents' => 20000,
            'track_stock' => true, 'inventory_item_id' => $this->cerveza->id,
        ]);

        $caja = app(OpenCashSession::class)($this->barra, null, 0);
        $orden = app(PlaceOrder::class)($caja, [
            ['product_id' => $producto->id, 'quantity' => 180],
        ], 'pos-0001');

        return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
    });

    expect($venta->status->value)->toBe('paid');

    comoElComercio(function (): void {
        // Se rompieron 12 en la nevera...
        app(RegisterWaste::class)($this->barra, $this->cerveza, 12, 'Se rompieron en la nevera');
        // ...y al cerrar se devuelven 40.
        app(ReturnFromEvent::class)($this->barra, $this->cerveza, 40);
    });

    $cuadre = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(EventStockReconciliation::class)->forEvent($this->event),
    );

    $linea = $cuadre->firstWhere('outletId', $this->barra->id);

    // 240 entregadas − 180 vendidas − 12 rotas − 40 devueltas = 8 que nadie
    // sabe dónde están. Esa es la pregunta del cierre.
    expect($linea->allocated)->toBe(240.0)
        ->and($linea->sold)->toBe(180.0)
        ->and($linea->wasted)->toBe(12.0)
        ->and($linea->returned)->toBe(40.0)
        ->and($linea->missing)->toBe(8.0)
        ->and($linea->missingPercent())->toBe(3.3)
        ->and($linea->itemName)->toBe('Presidente 350ml')
        ->and($linea->vendorName)->toBe('Cervecería del Malecón');
});

it('shows nothing missing when everything is accounted for', function (): void {
    comoElComercio(function (): void {
        app(AllocateToEvent::class)($this->barra, $this->cerveza, 100);
        app(ReturnFromEvent::class)($this->barra, $this->cerveza, 100);
    });

    $cuadre = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(EventStockReconciliation::class)->forEvent($this->event),
    );

    expect($cuadre->firstWhere('outletId', $this->barra->id)->missing)->toBe(0.0);
});

it('does not blame a count adjustment for the gap it already explained', function (): void {
    comoElComercio(function (): void {
        app(AllocateToEvent::class)($this->barra, $this->cerveza, 100);
        // Un conteo físico encuentra 10 menos y se registra como ajuste.
        app(AdjustStock::class)(
            $this->barra, $this->cerveza, -10, 'Conteo del cierre',
        );
        app(ReturnFromEvent::class)($this->barra, $this->cerveza, 90);
    });

    $linea = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(EventStockReconciliation::class)->forEvent($this->event),
    )->firstWhere('outletId', $this->barra->id);

    // El ajuste ya reconoció las 10 que faltaban: contarlas otra vez como
    // faltante las cobraría dos veces.
    expect($linea->adjusted)->toBe(-10.0)
        ->and($linea->missing)->toBe(0.0);
});

it('separates each outlet and each item', function (): void {
    $ron = comoElComercio(fn () => InventoryItem::create([
        'name' => 'Ron Barceló', 'base_unit' => 'ml', 'cost_cents' => 5,
    ]));

    comoElComercio(function () use ($ron): void {
        app(AllocateToEvent::class)($this->barra, $this->cerveza, 100);
        app(AllocateToEvent::class)($this->barra, $ron, 3000);
        app(AllocateToEvent::class)($this->bodega, $this->cerveza, 50);
    });

    $cuadre = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(EventStockReconciliation::class)->forEvent($this->event),
    );

    expect($cuadre)->toHaveCount(3);

    $enBarra = $cuadre->where('outletId', $this->barra->id);

    expect($enBarra)->toHaveCount(2)
        ->and($enBarra->firstWhere('itemName', 'Ron Barceló')->allocated)->toBe(3000.0)
        ->and($enBarra->firstWhere('itemName', 'Ron Barceló')->baseUnit)->toBe('ml');
});
