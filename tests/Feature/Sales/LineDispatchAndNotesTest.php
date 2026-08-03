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
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonInterface;

/**
 * Lo que la línea vendida tiene que recordar para que la cocina pueda
 * trabajar: de qué área sale, qué pidió el cliente que se le hiciera, y a
 * qué hora se cobró de verdad.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($evento, $this->vendor, 1000);
        $this->puesto = outletFor($evento, 'Puesto', OperatingUnitKind::Mixed, $this->vendor);

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $this->cocina = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $this->barra = Category::create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);

            $this->taco = Product::create([
                'category_id' => $this->cocina->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);
            $this->refresco = Product::create([
                'category_id' => $this->barra->id, 'name' => 'Refresco',
                'type' => ProductType::Simple, 'price_cents' => 10000,
            ]);
        });
    });

    $this->ref = 0;
    $this->caja = null;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Abre la caja del puesto la primera vez, y la reutiliza después. */
function laCaja(): CashSession
{
    return test()->caja ??= app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs(
            test()->vendor,
            fn () => app(OpenCashSession::class)(test()->puesto, null, 0),
        ),
    );
}

/** Registra una venta en el puesto y devuelve la orden. */
function vender(array $lines, ?CarbonInterface $soldAt = null): Order
{
    $caja = laCaja();

    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs(test()->vendor, fn (): Order => app(PlaceOrder::class)(
            $caja, $lines, 'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
            soldAt: $soldAt,
        )),
    );
}

it('freezes the dispatch area of each line as it was sold', function (): void {
    $orden = vender([
        ['product_id' => $this->taco->id, 'quantity' => 3],
        ['product_id' => $this->refresco->id, 'quantity' => 1],
    ]);

    $lineas = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => $orden->lines()->get()->keyBy('product_name'),
    );

    expect($lineas['Taco al pastor']->dispatch)->toBe(DispatchArea::Kitchen)
        ->and($lineas['Refresco']->dispatch)->toBe(DispatchArea::Bar);
});

it('never rewrites history when a product is recategorised later', function (): void {
    $orden = vender([['product_id' => $this->taco->id, 'quantity' => 2]]);

    // Al día siguiente alguien mueve los tacos a la categoría de la barra.
    app(TenantContext::class)->runAs($this->organizer, fn () => app(VendorContext::class)->runAs(
        $this->vendor,
        fn () => $this->taco->update(['category_id' => $this->barra->id]),
    ));

    $linea = app(TenantContext::class)->runAs($this->organizer, fn () => $orden->lines()->sole());

    // La comanda de ayer salió de la cocina, y eso ya no lo cambia nadie.
    expect($linea->dispatch)->toBe(DispatchArea::Kitchen);
});

it('carries the note of each line, trimmed and capped', function (): void {
    $orden = vender([
        ['product_id' => $this->taco->id, 'quantity' => 1, 'notes' => '  Sin cebolla  '],
        ['product_id' => $this->refresco->id, 'quantity' => 1, 'notes' => str_repeat('a', 200)],
        ['product_id' => $this->taco->id, 'quantity' => 1, 'notes' => '   '],
    ]);

    $notas = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => $orden->lines()->get()->pluck('notes')->all(),
    );

    expect($notas[0])->toBe('Sin cebolla')
        ->and(mb_strlen((string) $notas[1]))->toBe(120)
        // Una nota en blanco no es una nota: null, y no una línea vacía
        // impresa bajo el plato.
        ->and($notas[2])->toBeNull();
});

it('keeps the note out of the idempotency check', function (): void {
    $caja = laCaja();

    $reenviar = fn (?string $nota) => app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(VendorContext::class)->runAs($this->vendor, fn () => app(PlaceOrder::class)(
            $caja,
            [['product_id' => $this->taco->id, 'quantity' => 1, 'notes' => $nota]],
            'pos-9001',
        )),
    );

    $primera = $reenviar('Sin cebolla');

    // Un borrador guardado antes de que existieran las notas se reenvía sin
    // ellas: es la MISMA venta, no una referencia reutilizada.
    expect($reenviar(null)->id)->toBe($primera->id);
});

it('records the time the cashier actually charged, and distrusts a bad clock', function (): void {
    $hace9 = now()->subMinutes(9);

    expect(vender([['product_id' => $this->taco->id, 'quantity' => 1]], $hace9)
        ->device_sold_at?->toDateTimeString())->toBe($hace9->toDateTimeString());

    // El reloj de una tablet barata se desfasa. Una marca del futuro o de
    // hace una semana no es un retraso de sincronización: es un reloj mal
    // puesto, y pintar la espera del cliente con ella daría cifras absurdas.
    expect(vender([['product_id' => $this->taco->id, 'quantity' => 1]], now()->addHour())->device_sold_at)->toBeNull()
        ->and(vender([['product_id' => $this->taco->id, 'quantity' => 1]], now()->subWeek())->device_sold_at)->toBeNull()
        ->and(vender([['product_id' => $this->taco->id, 'quantity' => 1]])->device_sold_at)->toBeNull();
});
