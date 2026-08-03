<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\AdvanceKitchenTicket;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Kitchen\Queries\KitchenBoard;
use App\Domains\Kitchen\Queries\KitchenTicketView;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Actions\RefundOrder;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El tablero de cocina leído como lo que es: las ventas cobradas del puesto,
 * con el estado que alguien les haya puesto encima si es que se lo puso.
 *
 * Lo que se prueba aquí no es una consulta, es una promesa: que ninguna venta
 * puede quedarse fuera del tablero por un fallo de sincronización, porque no
 * hay nada que sincronizar — pendiente es no tener fila.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());
        $this->vendor = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($evento, $this->vendor, 1000);
        $this->puesto = outletFor($evento, 'Puesto mixto', OperatingUnitKind::Mixed, $this->vendor);
        $this->barra = outletFor($evento, 'Barra', OperatingUnitKind::Bar, $this->vendor);

        app(VendorContext::class)->runAs($this->vendor, function (): void {
            $cocina = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);
            $barra = Category::create(['name' => 'Bebidas', 'dispatch' => DispatchArea::Bar]);

            $this->taco = Product::create([
                'category_id' => $cocina->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);
            $this->refresco = Product::create([
                'category_id' => $barra->id, 'name' => 'Refresco',
                'type' => ProductType::Simple, 'price_cents' => 10000,
            ]);
        });
    });

    $this->ref = 0;
    $this->cajas = [];
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Corre algo con la cuenta y el comercio puestos, como haría una petición. */
function enElPuesto(Closure $callback): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs(test()->vendor, $callback),
    );
}

/** Abre la caja de una unidad la primera vez, y la reutiliza después. */
function cajaDe(EventOutlet $unidad): CashSession
{
    $cajas = test()->cajas;

    if (! isset($cajas[$unidad->id])) {
        $cajas[$unidad->id] = enElPuesto(fn (): CashSession => app(OpenCashSession::class)($unidad, null, 0));
        test()->cajas = $cajas;
    }

    return $cajas[$unidad->id];
}

/** Vende y cobra en una unidad: solo lo cobrado llega a la cocina. */
function venderYCobrar(
    array $lines,
    ?EventOutlet $unidad = null,
    ?string $cliente = null,
    ?CarbonInterface $soldAt = null,
): Order {
    $unidad ??= test()->puesto;
    $caja = cajaDe($unidad);

    return enElPuesto(function () use ($caja, $lines, $cliente, $soldAt): Order {
        $orden = app(PlaceOrder::class)(
            $caja,
            $lines,
            'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
            customerName: $cliente,
            soldAt: $soldAt,
        );

        return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
    });
}

/**
 * Pone una comanda en un estado, con sus sellos. Se escribe a mano y no por
 * la Action a propósito: lo que se prueba aquí es la LECTURA del tablero.
 */
function marcar(Order $orden, DispatchArea $area, KitchenTicketStatus $estado, array $sellos = []): KitchenTicket
{
    return enElPuesto(function () use ($orden, $area, $estado, $sellos): KitchenTicket {
        $comanda = new KitchenTicket(array_merge([
            'operating_unit_id' => $orden->operating_unit_id,
            'order_id' => $orden->id,
            'area' => $area,
            'items_count' => 1,
        ], $sellos));

        $comanda->status = $estado;
        $comanda->saveTransition();

        return $comanda;
    });
}

/** @return Collection<int, KitchenTicketView> */
function tableroDe(EventOutlet $unidad, ?DispatchArea $area = null): Collection
{
    return enElPuesto(fn (): Collection => app(KitchenBoard::class)->forUnits([$unidad->id], $area));
}

it('splits a mixed sale into one card per area, each knowing its sibling', function (): void {
    $orden = venderYCobrar([
        ['product_id' => $this->taco->id, 'quantity' => 2, 'notes' => 'Sin cebolla'],
        ['product_id' => $this->refresco->id, 'quantity' => 1],
    ], cliente: 'Marisol');

    $tablero = tableroDe($this->puesto)->keyBy(fn (KitchenTicketView $vista): string => $vista->area->value);

    // Nadie la ha tocado y aun así está en las dos pantallas: no hay fila en
    // kitchen_tickets, y ESO es exactamente lo que significa pendiente.
    expect($tablero)->toHaveCount(2)
        ->and(KitchenTicket::query()->count())->toBe(0)
        ->and($tablero['kitchen']->status)->toBe(KitchenTicketStatus::Pending)
        ->and($tablero['bar']->status)->toBe(KitchenTicketStatus::Pending)
        ->and($tablero['kitchen']->estadoHermano)->toBe(KitchenTicketStatus::Pending);

    // Cada tarjeta lleva SOLO lo suyo, con su nota y su cuenta de unidades.
    expect($tablero['kitchen']->lines)->toHaveCount(1)
        ->and($tablero['kitchen']->lines->first()->productName)->toBe('Taco al pastor')
        ->and($tablero['kitchen']->lines->first()->notes)->toBe('Sin cebolla')
        ->and($tablero['kitchen']->itemsCount)->toBe(2)
        ->and($tablero['bar']->itemsCount)->toBe(1)
        ->and($tablero['bar']->lines->first()->notes)->toBeNull();

    // El número ya viene cantado, y el nombre del cliente con él.
    expect($tablero['bar']->numero)->toBe($orden->publicNumber())
        ->and($tablero['bar']->customerName)->toBe('Marisol');

    marcar($orden, DispatchArea::Kitchen, KitchenTicketStatus::InProgress, ['started_at' => now()]);

    $conCocinaEmpezada = tableroDe($this->puesto)->keyBy(fn (KitchenTicketView $v): string => $v->area->value);

    // Nadie entrega media orden: la barra ve que la cocina sigue trabajando.
    expect($conCocinaEmpezada['kitchen']->status)->toBe(KitchenTicketStatus::InProgress)
        ->and($conCocinaEmpezada['kitchen']->startedAt)->not->toBeNull()
        ->and($conCocinaEmpezada['bar']->estadoHermano)->toBe(KitchenTicketStatus::InProgress)
        ->and($conCocinaEmpezada['kitchen']->estadoHermano)->toBe(KitchenTicketStatus::Pending);

    // Y filtrando por área sigue sabiendo de su hermana, que es el motivo de
    // que el estado se calcule antes de filtrar.
    $soloBarra = tableroDe($this->puesto, DispatchArea::Bar);

    expect($soloBarra)->toHaveCount(1)
        ->and($soloBarra->first()->area)->toBe(DispatchArea::Bar)
        ->and($soloBarra->first()->estadoHermano)->toBe(KitchenTicketStatus::InProgress);
});

it('drops a ticket that has been ready for a while but never one still open', function (): void {
    $vieja = venderYCobrar([['product_id' => $this->refresco->id, 'quantity' => 1]]);
    $reciente = venderYCobrar([['product_id' => $this->refresco->id, 'quantity' => 1]]);

    marcar($vieja, DispatchArea::Bar, KitchenTicketStatus::Ready, ['ready_at' => now()->subMinutes(30)]);
    marcar($reciente, DispatchArea::Bar, KitchenTicketStatus::Ready, ['ready_at' => now()->subMinutes(5)]);

    // Una venta de hace once horas que nadie tocó. No caduca: un pedido
    // olvidado tiene que seguir gritando hasta que alguien lo despache.
    $this->travelTo(now()->subHours(11));
    $olvidada = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);
    $this->travelBack();

    $ids = tableroDe($this->puesto)->pluck('orderId');

    expect($ids)->toContain($reciente->id)
        ->and($ids)->toContain($olvidada->id)
        ->and($ids)->not->toContain($vieja->id);
});

it('shows the refunded money without moving the state of the sale', function (): void {
    $orden = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 2]]);

    enElPuesto(fn () => app(RefundOrder::class)(
        $orden->fresh(), cajaDe($this->puesto), 20000, 'Salió frío',
    ));

    $tarjeta = tableroDe($this->puesto)->sole();

    // El reembolso es un hecho nuevo al lado de la venta: la cocina se entera
    // de que se devolvió dinero, pero la comanda sigue donde estaba y la
    // venta sigue cobrada — RefundOrder no escribe en orders.status.
    expect($tarjeta->refundedCents)->toBe(20000)
        ->and($tarjeta->status)->toBe(KitchenTicketStatus::Pending)
        // Una venta de una sola área no tiene hermana de la que preocuparse.
        ->and($tarjeta->estadoHermano)->toBeNull()
        ->and($orden->fresh()->status)->toBe(OrderStatus::Paid);
});

it('marks a sale that reached the kitchen long after it was charged', function (): void {
    $hace9 = now()->subMinutes(9);
    venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]], soldAt: $hace9);
    venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    $tablero = tableroDe($this->puesto);

    // El POS estuvo sin cobertura: el cliente lleva esperando desde SU reloj,
    // y la tarjeta tiene que decirlo para que nadie la trate como recién
    // llegada. Y el tablero no devuelve «hace 9 minutos» sino la marca: el
    // ETag del endpoint cambiaría cada segundo y el 304 no llegaría nunca.
    expect($tablero[0]->llegoTarde())->toBeTrue()
        ->and($tablero[0]->esperaDesde()->toDateTimeString())->toBe($hace9->toDateTimeString())
        ->and($tablero[1]->llegoTarde())->toBeFalse()
        ->and($tablero[1]->esperaDesde()->toDateTimeString())->toBe($tablero[1]->paidAt->toDateTimeString());
});

it('puts the oldest sale first', function (): void {
    $this->travelTo(now()->subMinutes(40));
    $primera = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    $this->travelTo(now()->addMinutes(25));
    $segunda = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    $this->travelBack();
    $tercera = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    // Quien lleva más esperando, arriba. Es el único orden que la cocina
    // puede seguir sin pensar.
    expect(tableroDe($this->puesto)->pluck('orderId')->all())
        ->toBe([$primera->id, $segunda->id, $tercera->id]);
});

it('sends a line without a frozen area to the area the unit declares', function (): void {
    $mixto = venderYCobrar([['product_id' => $this->refresco->id, 'quantity' => 1]]);
    $deBarra = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]], $this->barra);

    // Así son las líneas anteriores a la columna, y las de un producto que ya
    // no existe. Se escriben en crudo porque el modelo no deja tocar la
    // historia, que es justo lo que hace que estas filas sigan ahí.
    DB::table('order_lines')->whereIn('order_id', [$mixto->id, $deBarra->id])->update(['dispatch' => null]);

    // Una unidad mixta manda a cocina lo que no sabe colocar: un plato que no
    // sale en el tablero de cocina es un cliente de pie, mientras que una
    // bebida entre los platos es como mucho una molestia.
    expect(tableroDe($this->puesto)->sole()->area)->toBe(DispatchArea::Kitchen)
        ->and(tableroDe($this->barra)->sole()->area)->toBe(DispatchArea::Bar);
});

it('counts the same units before and after somebody touches the card', function (): void {
    // Tres refrescos en un solo renglón. El tablero cuenta los de una venta
    // que nadie ha tocado; AdvanceKitchenTicket congela los de la fila que
    // crea al tocarla. Si las dos cuentas no coinciden, el número CAMBIA
    // delante del cocinero justo al pulsar «Empezar», y nadie sabe cuál de
    // los dos era el bueno.
    $orden = venderYCobrar([['product_id' => $this->refresco->id, 'quantity' => 3]], $this->barra);

    expect(tableroDe($this->barra)->sole()->itemsCount)->toBe(3);

    enElPuesto(fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Bar, KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
    ));

    expect(tableroDe($this->barra)->sole()->itemsCount)->toBe(3)
        ->and(enElPuesto(fn () => KitchenTicket::query()->where('order_id', $orden->id)->sole())->items_count)
        ->toBe(3);
});

it('drops a fully refunded sale that never entered the kitchen', function (): void {
    // Se cobró, se devolvió entera, y nadie llegó a tocarla. Nadie va a
    // cocinar eso jamás.
    $devuelta = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    enElPuesto(fn () => app(RefundOrder::class)(
        $devuelta->fresh(), cajaDe($this->puesto), $devuelta->total_cents, 'Se arrepintió',
    ));

    // Y otra normal, para saber que el tablero sigue vivo.
    $buena = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    $tablero = tableroDe($this->puesto);

    // Sin esto la venta devuelta se quedaba PENDIENTE para siempre —
    // RefundOrder no toca orders.status a conciencia— y su reloj no paraba
    // nunca: cada noche acababa arrastrando a su comercio al primer puesto
    // del tablero del organizador por un plato que nadie iba a hacer.
    expect($tablero->pluck('orderId')->all())->toBe([$buena->id]);
});

it('keeps a partly refunded sale, because part of the food is still owed', function (): void {
    $orden = venderYCobrar([
        ['product_id' => $this->taco->id, 'quantity' => 1],
        ['product_id' => $this->refresco->id, 'quantity' => 1],
    ]);

    // Se devolvió una parte. Nadie sabe cuál —los reembolsos son un importe,
    // no unas líneas—, así que la comanda se queda y decide la cocina, que
    // para eso ve la franja roja de «DEVUELTA» en la tarjeta.
    enElPuesto(fn () => app(RefundOrder::class)(
        $orden->fresh(), cajaDe($this->puesto), 1000, 'Solo el refresco',
    ));

    expect(tableroDe($this->puesto)->pluck('orderId')->all())->toContain($orden->id);
});

it('keeps a fully refunded sale that the kitchen already started', function (): void {
    $orden = venderYCobrar([['product_id' => $this->taco->id, 'quantity' => 1]]);

    marcar($orden, DispatchArea::Kitchen, KitchenTicketStatus::InProgress, [
        'started_at' => now()->subMinutes(2),
    ]);

    enElPuesto(fn () => app(RefundOrder::class)(
        $orden->fresh(), cajaDe($this->puesto), $orden->total_cents, 'Tardaba mucho',
    ));

    // Esta NO se cae, y es a propósito: alguien la está cocinando ahora
    // mismo. Hacerla desaparecer de la pantalla dejaría a esa persona
    // haciendo un plato sin saber por qué se le fue la tarjeta. Se queda con
    // su franja de devuelta para que pueda parar y cerrarla.
    $tarjeta = tableroDe($this->puesto)->sole();

    expect($tarjeta->orderId)->toBe($orden->id)
        ->and($tarjeta->refundedCents)->toBe($orden->total_cents);
});
