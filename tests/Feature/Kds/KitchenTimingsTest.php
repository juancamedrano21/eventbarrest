<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Kitchen\Queries\KitchenTimings;
use App\Domains\Kitchen\Queries\KitchenTimingsReport;
use App\Domains\Kitchen\Queries\TimingBreakdown;
use App\Domains\Kitchen\Queries\TimingSummary;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Sales\Actions\OpenCashSession;
use App\Domains\Sales\Actions\PayOrder;
use App\Domains\Sales\Actions\PlaceOrder;
use App\Domains\Sales\Enums\PaymentMethod;
use App\Domains\Sales\Models\CashSession;
use App\Domains\Sales\Models\Order;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonInterface;

/**
 * Los tiempos de cocina medidos sobre marcas fabricadas a mano, para que
 * cada cifra del informe se pueda comprobar con una resta.
 *
 * Lo que se prueba aquí no es aritmética, es honestidad estadística: que una
 * comanda olvidada no arrastre la mediana, que lo que sigue abierto se
 * cuente aparte en vez de desaparecer, y que el retraso del wifi no acabe
 * dentro del tiempo de la cocina con el nombre de otro.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());

        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        $this->pizzas = vendorIn($this->evento, 'Pizza del Malecón');

        $this->puesto = outletFor($this->evento, 'Puesto mixto', OperatingUnitKind::Mixed, $this->tacos);
        $this->puestoVecino = outletFor($this->evento, 'Puesto vecino', OperatingUnitKind::Mixed, $this->pizzas);

        [$this->taco, $this->refresco] = catalogoDe($this->tacos);
        [$this->pizza] = catalogoDe($this->pizzas);
    });

    $this->ref = 0;
    $this->cajas = [];

    // Todo el informe cuelga de este instante: las ventas se fabrican hacia
    // adelante desde aquí y la ventana se pide alrededor. Dos horas atrás
    // deja sitio de sobra para colas y preparaciones sin caer en el futuro.
    $this->base = now()->subHours(2);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Corre algo con la cuenta y el comercio puestos, como haría una petición. */
function conComercio(Vendor $vendor, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs($vendor, $callback),
    );
}

/**
 * Un plato de cocina y una bebida de barra para el comercio.
 *
 * @return array{0: Product, 1: Product}
 */
function catalogoDe(Vendor $vendor): array
{
    return conComercio($vendor, function () use ($vendor): array {
        $cocina = Category::create(['name' => 'Comida '.$vendor->id, 'dispatch' => DispatchArea::Kitchen]);
        $barra = Category::create(['name' => 'Bebidas '.$vendor->id, 'dispatch' => DispatchArea::Bar]);

        return [
            Product::create([
                'category_id' => $cocina->id, 'name' => 'Plato '.$vendor->id,
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]),
            Product::create([
                'category_id' => $barra->id, 'name' => 'Bebida '.$vendor->id,
                'type' => ProductType::Simple, 'price_cents' => 10000,
            ]),
        ];
    });
}

/** Abre la caja de una unidad la primera vez y la reutiliza después. */
function cajaMedida(EventOutlet $unidad, Vendor $vendor): CashSession
{
    $cajas = test()->cajas;

    if (! isset($cajas[$unidad->id])) {
        $cajas[$unidad->id] = conComercio($vendor, fn (): CashSession => app(OpenCashSession::class)($unidad, null, 0));
        test()->cajas = $cajas;
    }

    return $cajas[$unidad->id];
}

/**
 * Fabrica una venta con sus cuatro marcas puestas al segundo.
 *
 * `$red` son los segundos que el POS tardó en sincronizar (null = la venta
 * no trae hora del cajero). `$started` y `$ready` se cuentan DESDE paid_at;
 * null en los dos significa que nadie tocó la comanda y por tanto no hay
 * fila en kitchen_tickets, que es como se guarda «pendiente».
 */
function ventaMedida(
    EventOutlet $unidad,
    Vendor $vendor,
    Product $producto,
    DispatchArea $area,
    CarbonInterface $vendidaEn,
    ?int $red = 0,
    ?int $started = null,
    ?int $ready = null,
): Order {
    $caja = cajaMedida($unidad, $vendor);
    $cobradaEn = $vendidaEn->copy()->addSeconds($red ?? 0);

    // El reloj del servidor manda sobre paid_at y PlaceOrder solo se cree la
    // hora del cajero si es verosímil desde «ahora»: por eso hay que viajar
    // a cada momento en vez de escribir las columnas a mano.
    test()->travelTo($vendidaEn);

    $orden = conComercio($vendor, fn (): Order => app(PlaceOrder::class)(
        $caja,
        [['product_id' => $producto->id, 'quantity' => 1]],
        'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
        soldAt: $red === null ? null : $vendidaEn,
    ));

    test()->travelTo($cobradaEn);

    $orden = conComercio($vendor, fn (): Order => app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents));

    if ($started !== null || $ready !== null) {
        conComercio($vendor, function () use ($orden, $area, $unidad, $cobradaEn, $started, $ready): void {
            $comanda = new KitchenTicket([
                'operating_unit_id' => $unidad->id,
                'order_id' => $orden->id,
                'area' => $area,
                'items_count' => 1,
                'started_at' => $started === null ? null : $cobradaEn->copy()->addSeconds($started),
                'ready_at' => $ready === null ? null : $cobradaEn->copy()->addSeconds($ready),
            ]);

            // Se escribe el estado final de un toque: Pendiente → Lista es
            // una transición legítima, y lo que se prueba aquí es la lectura.
            $comanda->status = $ready === null ? KitchenTicketStatus::InProgress : KitchenTicketStatus::Ready;
            $comanda->saveTransition();
        });
    }

    test()->travelBack();

    return $orden;
}

/**
 * El informe del organizador: sin comercio en contexto, ve el evento entero.
 *
 * @param  array<int, int>  $unidades
 */
function informeDeUnidades(array $unidades): KitchenTimingsReport
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn (): KitchenTimingsReport => app(KitchenTimings::class)->forUnits(
            $unidades,
            test()->base->copy()->subHour(),
            test()->base->copy()->addHours(3),
        ),
    );
}

function informeDelEvento(Event $evento): KitchenTimingsReport
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn (): KitchenTimingsReport => app(KitchenTimings::class)->forEvent(
            $evento,
            test()->base->copy()->subHour(),
            test()->base->copy()->addHours(3),
        ),
    );
}

it('measures the three stretches with the seconds that were actually stamped', function (): void {
    // Cinco iguales: el mínimo para que el informe se atreva a dar cifras.
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 60, started: 120, ready: 420,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->cola->medianSeconds)->toBe(120)
        ->and($informe->preparando->medianSeconds)->toBe(300)
        // 60 de red + 120 de cola + 300 cocinando: lo que el cliente esperó.
        ->and($informe->espera->medianSeconds)->toBe(480)
        ->and($informe->syncDelay->medianSeconds)->toBe(60)
        ->and($informe->readyCount)->toBe(5)
        ->and($informe->openCount)->toBe(0);
});

it('keeps the median steady when one forgotten ticket would wreck an average', function (): void {
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 300,
        );
    }

    // La que alguien marcó lista tres horas tarde. En una media subiría el
    // tiempo de preparación de cinco minutos a media hora larga.
    ventaMedida(
        $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
        $this->base->copy()->addMinutes(6),
        red: 0, started: 0, ready: 10800,
    );

    $informe = informeDeUnidades([$this->puesto->id]);

    $media = (300 * 5 + 10800) / 6;

    expect($informe->preparando->medianSeconds)->toBe(300)
        ->and($informe->preparando->medianSeconds)->toBeLessThan((int) $media)
        // El disparate no se borra: se enseña donde le toca, en el peor caso.
        ->and($informe->preparando->worstSeconds)->toBe(10800)
        ->and($informe->preparando->samples)->toBe(6);
});

it('reads p90 as the comanda that one in ten waited more than', function (): void {
    // Ocho rápidas, una regular y una mala: diez en total, y la frase «una
    // de cada diez esperó más que esto» tiene que señalar a los 400.
    foreach (range(0, 7) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 120,
        );
    }

    foreach ([400, 900] as $j => $tarda) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes(9 + $j),
            red: 0, started: 0, ready: $tarda,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->preparando->samples)->toBe(10)
        ->and($informe->preparando->medianSeconds)->toBe(120)
        // Exactamente una de las diez —la de 900— tardó más que esto. Y es
        // un tiempo que ocurrió de verdad, no un punto interpolado.
        ->and($informe->preparando->p90Seconds)->toBe(400)
        ->and($informe->preparando->worstSeconds)->toBe(900);
});

it('collapses p90 onto the worst comanda at the minimum sample size', function (): void {
    // Con cinco comandas el 90 % son cuatro, y el p90 acaba siendo la peor:
    // el informe da la cifra, pero quien la lea tiene que saber que con
    // cinco datos el p90 y el peor caso son la misma comanda.
    foreach ([60, 90, 120, 150, 600] as $j => $tarda) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($j),
            red: 0, started: 0, ready: $tarda,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->preparando->enoughData())->toBeTrue()
        ->and($informe->preparando->medianSeconds)->toBe(120)
        ->and($informe->preparando->p90Seconds)->toBe(600)
        ->and($informe->preparando->p90Seconds)->toBe($informe->preparando->worstSeconds);
});

it('says too few data instead of giving a figure nobody can defend', function (): void {
    foreach (range(0, 3) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 300,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect(TimingSummary::MINIMO_DE_COMANDAS)->toBe(5)
        ->and($informe->preparando->samples)->toBe(4)
        ->and($informe->preparando->enoughData())->toBeFalse()
        ->and($informe->preparando->medianSeconds)->toBeNull()
        ->and($informe->preparando->p90Seconds)->toBeNull()
        // El peor caso sí sale: no es una estimación, es algo que ocurrió.
        ->and($informe->preparando->worstSeconds)->toBe(300)
        ->and($informe->preparando->isEmpty())->toBeFalse();
});

it('counts an open comanda as open instead of as a fast one', function (): void {
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 120,
        );
    }

    // Una que alguien empezó y dejó a medias.
    ventaMedida(
        $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
        $this->base->copy()->addMinutes(6),
        red: 0, started: 30, ready: null,
    );

    // Y la peor de todas: la que nadie tocó jamás, que ni siquiera tiene
    // fila en kitchen_tickets. Si el informe leyera esa tabla, no existiría.
    ventaMedida(
        $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
        $this->base->copy()->addMinutes(7),
    );

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->readyCount)->toBe(5)
        ->and($informe->openCount)->toBe(2)
        // Las abiertas no entran en ninguna mediana: si entrasen como
        // «rápidas» el puesto saldría mejor cuanto peor trabajase.
        ->and($informe->espera->samples)->toBe(5)
        ->and($informe->preparando->medianSeconds)->toBe(120)
        // La más vieja lleva desde hace casi dos horas, no desde ahora.
        ->and($informe->oldestOpenSeconds)->toBeGreaterThan(6600)
        ->and($informe->oldestOpenSeconds)->toBeLessThan(7500);
});

it('keeps the sync delay out of the preparation time', function (): void {
    // El POS estuvo diez minutos sin cobertura. La cocina, en cuanto vio la
    // comanda, tardó tres minutos en total.
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 600, started: 60, ready: 180,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->syncDelay->medianSeconds)->toBe(600)
        ->and($informe->cola->medianSeconds)->toBe(60)
        // Los diez minutos de wifi NO están aquí dentro: la cocina responde
        // de los dos minutos que tardó desde que pudo ver el pedido.
        ->and($informe->preparando->medianSeconds)->toBe(120)
        ->and($informe->espera->medianSeconds)->toBe(780)
        // Y la diferencia se enseña: la espera no es cola + preparando.
        ->and($informe->esperaSinExplicar())->toBe(600)
        ->and($informe->cuelloDeBotella()?->label)->toBe(KitchenTimingsReport::RETRASO_DE_RED)
        ->and($informe->elCuelloEsDeLaRed())->toBeTrue();
});

it('blames the kitchen only when the kitchen is the slow part', function (): void {
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 30, started: 60, ready: 660,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->cuelloDeBotella()?->label)->toBe(KitchenTimingsReport::PREPARANDO)
        ->and($informe->elCuelloEsDeLaRed())->toBeFalse()
        // Diez minutos cocinando sobre once y medio de espera.
        ->and($informe->pesoSobreLaEspera($informe->preparando))->toBeGreaterThan(80.0);
});

it('leaves a comanda that never went in progress out of the kitchen stretches', function (): void {
    // Cinco cervezas servidas de un solo toque: sin started_at no hay ni
    // cola ni preparación que medir, pero el cliente sí esperó.
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->refresco, DispatchArea::Bar,
            $this->base->copy()->addMinutes($i),
            red: 0, started: null, ready: 45,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->espera->samples)->toBe(5)
        ->and($informe->espera->medianSeconds)->toBe(45)
        ->and($informe->cola->isEmpty())->toBeTrue()
        ->and($informe->preparando->isEmpty())->toBeTrue()
        ->and($informe->esperaSinExplicar())->toBeNull();
});

it('never mixes the bar and the kitchen in the same row', function (): void {
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 600,
        );
        ventaMedida(
            $this->puesto, $this->tacos, $this->refresco, DispatchArea::Bar,
            $this->base->copy()->addMinutes(30 + $i),
            red: 0, started: 0, ready: 30,
        );
    }

    $informe = informeDeUnidades([$this->puesto->id]);

    $cocina = $informe->breakdown->firstWhere(fn (TimingBreakdown $f): bool => $f->area === DispatchArea::Kitchen);
    $barra = $informe->breakdown->firstWhere(fn (TimingBreakdown $f): bool => $f->area === DispatchArea::Bar);

    expect($informe->breakdown)->toHaveCount(2)
        ->and($cocina?->preparando->medianSeconds)->toBe(600)
        ->and($barra?->preparando->medianSeconds)->toBe(30)
        // Lo más lento arriba: es donde hay que mirar.
        ->and($informe->breakdown->first()?->area)->toBe(DispatchArea::Kitchen);
});

it('never lets one vendor read another vendor timings', function (): void {
    foreach (range(0, 4) as $i) {
        ventaMedida(
            $this->puesto, $this->tacos, $this->taco, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 120,
        );
        ventaMedida(
            $this->puestoVecino, $this->pizzas, $this->pizza, DispatchArea::Kitchen,
            $this->base->copy()->addMinutes($i),
            red: 0, started: 0, ready: 1200,
        );
    }

    $solo = informeDeUnidades([$this->puesto->id]);

    expect($solo->readyCount)->toBe(5)
        ->and($solo->preparando->medianSeconds)->toBe(120)
        ->and($solo->breakdown)->toHaveCount(1)
        ->and($solo->breakdown->first()?->vendorName)->toBe('Tacos del Puerto');

    // El organizador, en cambio, ve el evento entero y compara comercios.
    $todo = informeDelEvento($this->evento);

    expect($todo->readyCount)->toBe(10)
        ->and($todo->breakdown)->toHaveCount(2)
        ->and($todo->breakdown->first()?->vendorName)->toBe('Pizza del Malecón');
});

it('shows an empty report when nothing was sold', function (): void {
    $informe = informeDeUnidades([$this->puesto->id]);

    expect($informe->isEmpty())->toBeTrue()
        ->and($informe->espera->isEmpty())->toBeTrue()
        ->and($informe->cuelloDeBotella())->toBeNull()
        ->and($informe->oldestOpenSeconds)->toBeNull()
        ->and($informe->breakdown)->toHaveCount(0);
});

it('never loses the untouched half of a mixed sale from the open count', function (): void {
    // Una venta con barra y cocina. La barra se sirvió; la cocina no la tocó
    // NADIE, así que ni siquiera tiene fila —pendiente es la ausencia de fila—.
    $caja = cajaMedida($this->puesto, $this->tacos);
    $vendidaEn = $this->base->copy();

    test()->travelTo($vendidaEn);

    $orden = conComercio($this->tacos, fn (): Order => app(PlaceOrder::class)(
        $caja,
        [
            ['product_id' => $this->refresco->id, 'quantity' => 1],
            ['product_id' => $this->taco->id, 'quantity' => 1],
        ],
        'pos-mixta-1',
    ));

    conComercio($this->tacos, fn () => app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents));

    conComercio($this->tacos, function () use ($orden, $vendidaEn): void {
        $comanda = new KitchenTicket([
            'operating_unit_id' => $this->puesto->id,
            'order_id' => $orden->id,
            'area' => DispatchArea::Bar,
            'items_count' => 1,
            'started_at' => $vendidaEn->copy()->addSeconds(10),
            'ready_at' => $vendidaEn->copy()->addSeconds(40),
        ]);
        $comanda->status = KitchenTicketStatus::Ready;
        $comanda->saveTransition();
    });

    test()->travelBack();

    $informe = informeDeUnidades([$this->puesto->id]);

    // La cocina de esa venta lleva horas colgada y tiene que salir. Con un
    // LEFT JOIN filtrando por ready_at nulo desaparecía: la única fila que
    // producía el join era la de la barra, que está lista y se descartaba —
    // y con ella se iba la venta entera del recuento.
    expect($informe->openCount)->toBe(1)
        ->and($informe->readyCount)->toBe(1);

    $cocina = $informe->breakdown->first(
        fn ($fila): bool => $fila->area === DispatchArea::Kitchen,
    );

    expect($cocina)->not->toBeNull()
        ->and($cocina->openCount)->toBe(1);
});
