<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\AdvanceKitchenTicket;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;

/**
 * El toque en la tarjeta del tablero: quién mueve una comanda, desde dónde
 * creía que la movía y qué pasa cuando dos tablets tocan la misma.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $event = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());

        // Un puesto mixto: la misma venta puede llevar trago y comida, que
        // es justo el caso que parte la orden en dos comandas.
        $this->puesto = outletFor($event, 'Puesto Central', OperatingUnitKind::Mixed);
        $this->vendor = $this->puesto->vendor;

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $barra = Category::create(['name' => 'Tragos', 'dispatch' => DispatchArea::Bar]);
            $cocina = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            $this->cerveza = Product::create([
                'category_id' => $barra->id, 'name' => 'Presidente',
                'type' => ProductType::Simple, 'price_cents' => 20000,
            ]);
            $this->ron = Product::create([
                'category_id' => $barra->id, 'name' => 'Cuba Libre',
                'type' => ProductType::Simple, 'price_cents' => 40000,
            ]);
            $this->taco = Product::create([
                'category_id' => $cocina->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);

            $this->caja = app(OpenCashSession::class)($this->puesto, null, 0);
        });
    });

    $this->ref = 0;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Ejecuta algo como la tablet del comercio: su cuenta y su comercio. */
function comoLaTabletDelPuesto(Closure $fn): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs(test()->vendor, $fn),
    );
}

/**
 * Una venta cobrada con las líneas que se le pidan. Cobrar es parte del
 * helper porque en el POS real la venta y su cobro son una sola transacción
 * y la cocina nunca ve una cosa sin la otra.
 *
 * @param  array<int, array{product_id: int, quantity: int}>  $lines
 */
function ventaYaCobrada(array $lines, bool $cobrar = true): Order
{
    return comoLaTabletDelPuesto(function () use ($lines, $cobrar): Order {
        test()->ref++;

        $orden = app(PlaceOrder::class)(
            test()->caja, $lines, 'pos-'.str_pad((string) test()->ref, 4, '0', STR_PAD_LEFT),
        );

        return $cobrar
            ? app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents)
            : $orden;
    });
}

it('creates the ticket on the first touch with the items of its area', function (): void {
    $orden = ventaYaCobrada([
        ['product_id' => $this->cerveza->id, 'quantity' => 3],
        ['product_id' => $this->ron->id, 'quantity' => 1],
    ]);

    $comanda = comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Bar,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
        deviceId: 77,
    ));

    // Antes del toque no había fila: pendiente es la ausencia de fila, y es
    // este toque —no la venta— el que la trae al mundo.
    expect($comanda->exists)->toBeTrue()
        ->and($comanda->status)->toBe(KitchenTicketStatus::InProgress)
        // UNIDADES, no renglones: tres cervezas y un ron son cuatro cosas que
        // servir. Es la misma cuenta que hace el tablero para las comandas
        // que todavía no tienen fila, y tienen que decir lo mismo — si no,
        // tocar la tarjeta cambiaría el número delante del cocinero.
        ->and($comanda->items_count)->toBe(4)
        ->and($comanda->operating_unit_id)->toBe($this->puesto->id)
        ->and($comanda->getAttribute('vendor_id'))->toBe($this->vendor->id)
        ->and($comanda->started_at)->not->toBeNull()
        ->and($comanda->started_by_device_id)->toBe(77)
        ->and($comanda->ready_at)->toBeNull();
});

it('splits a mixed order into two tickets that move on their own', function (): void {
    $orden = ventaYaCobrada([
        ['product_id' => $this->cerveza->id, 'quantity' => 2],
        ['product_id' => $this->ron->id, 'quantity' => 1],
        ['product_id' => $this->taco->id, 'quantity' => 4],
    ]);

    // La barra sirve al momento; la cocina todavía ni ha empezado.
    comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Bar,
        KitchenTicketStatus::Pending, KitchenTicketStatus::Ready,
    ));

    $comandas = comoLaTabletDelPuesto(fn () => KitchenTicket::query()
        ->where('order_id', $orden->id)->get()->keyBy(fn ($t) => $t->area->value));

    // La cocina no tiene fila: nadie la ha tocado, y eso ya significa
    // pendiente sin que nadie haya tenido que escribirlo.
    expect($comandas)->toHaveCount(1)
        ->and($comandas['bar']->status)->toBe(KitchenTicketStatus::Ready)
        // Dos cervezas y un ron: tres cosas que servir en la barra.
        ->and($comandas['bar']->items_count)->toBe(3);

    comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
    ));

    $comandas = comoLaTabletDelPuesto(fn () => KitchenTicket::query()
        ->where('order_id', $orden->id)->get()->keyBy(fn ($t) => $t->area->value));

    expect($comandas)->toHaveCount(2)
        ->and($comandas['bar']->status)->toBe(KitchenTicketStatus::Ready)
        ->and($comandas['kitchen']->status)->toBe(KitchenTicketStatus::InProgress)
        // Y cuatro tacos, que son cuatro tacos y no «una línea».
        ->and($comandas['kitchen']->items_count)->toBe(4);
});

it('refuses the second tablet that comes from a stale state', function (): void {
    $orden = ventaYaCobrada([['product_id' => $this->taco->id, 'quantity' => 2]]);

    // La cocinera la pone en proceso desde su tablet.
    comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
        deviceId: 1,
    ));

    // El ayudante, con una pantalla de hace tres segundos que todavía la ve
    // pendiente, la marca lista de un tirón. La matriz permite ese salto,
    // así que sin el control por estado de origen le pasaría por encima.
    $fallo = null;

    try {
        comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
            $orden, DispatchArea::Kitchen,
            KitchenTicketStatus::Pending, KitchenTicketStatus::Ready,
            deviceId: 2,
        ));
    } catch (KitchenException $e) {
        $fallo = $e;
    }

    expect($fallo)->not->toBeNull()
        ->and($fallo->errorCode)->toBe('kitchen_status_changed')
        ->and($fallo->httpStatus)->toBe(409);

    $comanda = comoLaTabletDelPuesto(fn () => KitchenTicket::query()->where('order_id', $orden->id)->sole());

    // Lo de la primera tablet sigue en pie, y sin sellos de la segunda.
    expect($comanda->status)->toBe(KitchenTicketStatus::InProgress)
        ->and($comanda->started_by_device_id)->toBe(1)
        ->and($comanda->ready_at)->toBeNull();
});

it('does nothing and breaks nothing when the same transition is repeated', function (): void {
    $orden = ventaYaCobrada([['product_id' => $this->taco->id, 'quantity' => 1]]);

    $primera = comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
        deviceId: 5,
    ));

    // El wifi del festival se comió la respuesta y la tablet reintenta, ya
    // sabiendo dónde estaba: pedir lo que ya se cumplió no es un error.
    $segunda = comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::InProgress, KitchenTicketStatus::InProgress,
        deviceId: 9,
    ));

    expect($segunda->id)->toBe($primera->id)
        ->and($segunda->status)->toBe(KitchenTicketStatus::InProgress)
        // El sello es el del toque que de verdad ocurrió, no el del eco.
        ->and($segunda->started_by_device_id)->toBe(5)
        ->and($segunda->started_at?->equalTo($primera->started_at))->toBeTrue()
        ->and(comoLaTabletDelPuesto(fn () => KitchenTicket::query()->where('order_id', $orden->id)->count()))->toBe(1);
});

it('clears the timestamp it undoes when it goes back', function (): void {
    $orden = ventaYaCobrada([['product_id' => $this->taco->id, 'quantity' => 1]]);

    comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
        deviceId: 3,
    ));

    $lista = comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::InProgress, KitchenTicketStatus::Ready,
        deviceId: 3,
    ));

    expect($lista->ready_at)->not->toBeNull()
        ->and($lista->ready_by_device_id)->toBe(3);

    // «Esto no estaba listo»: se deshace el listo, no el arranque.
    $vuelta = comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::Ready, KitchenTicketStatus::InProgress,
        deviceId: 4,
    ));

    expect($vuelta->ready_at)->toBeNull()
        ->and($vuelta->ready_by_device_id)->toBeNull()
        ->and($vuelta->started_at)->not->toBeNull()
        ->and($vuelta->started_by_device_id)->toBe(3);

    // Y un paso más atrás: ni siquiera se había empezado.
    $pendiente = comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen,
        KitchenTicketStatus::InProgress, KitchenTicketStatus::Pending,
        deviceId: 4,
    ));

    expect($pendiente->status)->toBe(KitchenTicketStatus::Pending)
        ->and($pendiente->started_at)->toBeNull()
        ->and($pendiente->started_by_device_id)->toBeNull();
});

it('keeps an order that was never paid out of the kitchen', function (): void {
    $abierta = ventaYaCobrada([['product_id' => $this->taco->id, 'quantity' => 1]], cobrar: false);

    $tocar = fn () => comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $abierta, DispatchArea::Kitchen,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
    ));

    expect($tocar)->toThrow(KitchenException::class, 'Solo se cocina una venta cobrada.')
        ->and(comoLaTabletDelPuesto(fn () => KitchenTicket::query()->count()))->toBe(0);
});

it('refuses an area that has nothing to dispatch', function (): void {
    $soloBarra = ventaYaCobrada([['product_id' => $this->cerveza->id, 'quantity' => 2]]);

    $tocar = fn () => comoLaTabletDelPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $soloBarra, DispatchArea::Kitchen,
        KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
    ));

    // Sin este guard, la cocina vería tarjetas vacías de una venta que se
    // despachó entera por la barra.
    expect($tocar)->toThrow(KitchenException::class);
});
