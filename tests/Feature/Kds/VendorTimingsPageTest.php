<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Kitchen\Queries\KitchenTimings;
use App\Domains\Kitchen\Queries\KitchenTimingsReport;
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
use Illuminate\Support\Carbon;

/**
 * La pantalla de tiempos en la puerta del comercio.
 *
 * Lo que se prueba aquí no es que pinte bien: es que solo pinte lo suyo. El
 * aislamiento no está escrito en el controlador —lo pone el contexto de
 * comercio que fija el middleware—, y una pieza que nadie escribió a mano es
 * exactamente la que un día desaparece sin que salte nada. De ahí el test
 * que importa: dos comercios en el mismo evento, y ni una comanda del vecino.
 */
beforeEach(function (): void {
    // La jornada se corta en hora de RD, así que con el reloj real esta
    // prueba diría una cosa a las once de la noche y otra a la una de la
    // madrugada. Se fija un sábado por la noche y se trabaja dentro de él.
    //
    // En UTC, y no es cosmético: Eloquent escribe una fecha con el formato
    // que trae puesto, sin convertirla, así que un Carbon en hora de RD
    // acabaría en la base de datos con la hora de la pared y saldría de aquí
    // con cuatro horas de retraso de red inventadas.
    $this->ahora = Carbon::parse('2026-05-16 21:00:00', 'America/Santo_Domingo')->utc();
    $this->travelTo($this->ahora);

    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());

        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        $this->pizzas = vendorIn($this->evento, 'Pizza del Malecón');

        $this->puesto = outletFor($this->evento, 'Carreta del taco', OperatingUnitKind::Kitchen, $this->tacos);
        $this->puestoVecino = outletFor($this->evento, 'Horno del vecino', OperatingUnitKind::Kitchen, $this->pizzas);

        $this->plato = platoDeLaPantalla($this->tacos);
        $this->platoVecino = platoDeLaPantalla($this->pizzas);
    });

    $this->encargada = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->tacos,
    );

    $this->ref = 0;
    $this->cajas = [];

    // Dos horas antes del corte: deja sitio para colas de media hora sin que
    // ninguna comanda acabe en el futuro ni se salga de la jornada.
    $this->base = $this->ahora->copy()->subHours(2);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

it('opens the timings screen for the vendor staff with their own advice', function (): void {
    // Se cogen tarde y se cocinan rápido: el cuello es la cola, y a un
    // comercio eso se le dice «te faltan manos», no «vas lento».
    for ($i = 0; $i < 6; $i++) {
        ventaDeLaPantalla($this->puesto, $this->tacos, $this->plato, started: 300, ready: 360);
    }

    $this->actingAs($this->encargada)
        ->get('/event-vendor/tiempos')
        ->assertOk()
        ->assertSee('Tiempos de despacho')
        ->assertSee('Tacos del Puerto')
        ->assertSee('Carreta del taco')
        ->assertSee('Qué hacer con esto')
        ->assertSee('Te faltan manos, no destreza')
        // La tabla llega del parcial compartido, no de una copia de esta
        // pantalla: si un día deja de pintarse, este test lo canta.
        ->assertSee('Preparando')
        ->assertSee('5 min');
});

it('never shows a vendor a single comanda from another vendor of the same event', function (): void {
    // Lo suyo: rápido. Lo del vecino: un desastre, y con nombres propios que
    // no pueden aparecer en ningún sitio de esta pantalla.
    for ($i = 0; $i < 6; $i++) {
        ventaDeLaPantalla($this->puesto, $this->tacos, $this->plato, started: 60, ready: 120);
        ventaDeLaPantalla($this->puestoVecino, $this->pizzas, $this->platoVecino, started: 1800, ready: 3600);
    }

    // El organizador, sin comercio en contexto, ve las doce: los datos del
    // vecino existen de verdad y están dentro de la misma ventana. Sin esto,
    // la prueba de abajo pasaría igual con la base de datos vacía.
    $delEvento = app(TenantContext::class)->runAs(
        $this->organizer,
        fn (): KitchenTimingsReport => app(KitchenTimings::class)->forUnits(
            [$this->puesto->id, $this->puestoVecino->id],
            $this->base->copy()->subHour(),
            $this->ahora,
        ),
    );

    expect($delEvento->readyCount)->toBe(12);

    $response = $this->actingAs($this->encargada)->get('/event-vendor/tiempos')->assertOk();

    /** @var KitchenTimingsReport $informe */
    $informe = $response->viewData('informe');

    expect($informe->readyCount)->toBe(6)
        ->and($informe->openCount)->toBe(0)
        ->and($informe->breakdown->pluck('vendorId')->unique()->all())->toBe([$this->tacos->id])
        ->and($informe->breakdown->pluck('unitId')->unique()->all())->toBe([$this->puesto->id])
        // Un minuto de cola: la del vecino son treinta, y mezclarlas movería
        // esta cifra aunque el nombre no llegara a pintarse.
        ->and($informe->cola->medianSeconds)->toBe(60);

    $response->assertDontSee('Pizza del Malecón')->assertDontSee('Horno del vecino');
});

it('keeps the organizer team out of this door', function (): void {
    $duena = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    $this->actingAs($duena)->get('/event-vendor/tiempos')->assertRedirect('/event-panel');
});

it('closes the screen to vendor staff without unit reports', function (): void {
    // Almacén entra por la puerta del comercio —compra y recibe— pero no lee
    // ventas, y los tiempos son un reporte de unidad como cualquier otro.
    $almacen = app(CreateTenantUser::class)(
        $this->organizer, 'Bea', 'bea@x.test', 'Secreta-2026', Role::Warehouse, $this->tacos,
    );

    $this->actingAs($almacen)->get('/event-vendor/tiempos')->assertForbidden();
});

it('falls back to today when the day in the url is not a date', function (): void {
    ventaDeLaPantalla($this->puesto, $this->tacos, $this->plato, started: 60, ready: 120);

    $response = $this->actingAs($this->encargada)->get('/event-vendor/tiempos?dia=el-sabado')->assertOk();

    expect($response->viewData('esHoy'))->toBeTrue()
        ->and($response->viewData('informe')->readyCount)->toBe(1);
});

it('reads the night before without dragging today into it', function (): void {
    ventaDeLaPantalla($this->puesto, $this->tacos, $this->plato, started: 60, ready: 120);

    $response = $this->actingAs($this->encargada)
        ->get('/event-vendor/tiempos?dia=2026-05-15')
        ->assertOk()
        ->assertSee('No hubo ventas en tus puestos ese día.');

    expect($response->viewData('esHoy'))->toBeFalse()
        ->and($response->viewData('informe')->readyCount)->toBe(0);
});

/** Corre algo con la cuenta y el comercio puestos, como haría una petición. */
function enSuComercio(Vendor $vendor, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs($vendor, $callback),
    );
}

/** Un plato de cocina, que es lo único que esta pantalla necesita medir. */
function platoDeLaPantalla(Vendor $vendor): Product
{
    return enSuComercio($vendor, function () use ($vendor): Product {
        $categoria = Category::create(['name' => 'Comida '.$vendor->id, 'dispatch' => DispatchArea::Kitchen]);

        return Product::create([
            'category_id' => $categoria->id, 'name' => 'Plato '.$vendor->id,
            'type' => ProductType::Simple, 'price_cents' => 25000,
        ]);
    });
}

/** Abre la caja de un puesto la primera vez y la reutiliza después. */
function cajaDeLaPantalla(EventOutlet $unidad, Vendor $vendor): CashSession
{
    $cajas = test()->cajas;

    if (! isset($cajas[$unidad->id])) {
        $cajas[$unidad->id] = enSuComercio($vendor, fn (): CashSession => app(OpenCashSession::class)($unidad, null, 0));
        test()->cajas = $cajas;
    }

    return $cajas[$unidad->id];
}

/**
 * Una venta cobrada con su comanda ya marcada, con los segundos contados
 * desde el cobro.
 *
 * Hay que viajar en el tiempo en vez de escribir las columnas a mano porque
 * `paid_at` lo pone el reloj del servidor y PlaceOrder solo se cree la hora
 * del cajero si es verosímil desde «ahora». Al salir se vuelve al instante
 * fijado en beforeEach y no al reloj real: la jornada del informe depende de
 * él.
 */
function ventaDeLaPantalla(
    EventOutlet $unidad,
    Vendor $vendor,
    Product $producto,
    int $started,
    int $ready,
): Order {
    $caja = cajaDeLaPantalla($unidad, $vendor);
    $vendidaEn = test()->base;

    test()->travelTo($vendidaEn);

    $orden = enSuComercio($vendor, fn (): Order => app(PlaceOrder::class)(
        $caja,
        [['product_id' => $producto->id, 'quantity' => 1]],
        'pos-'.$unidad->id.'-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
        soldAt: $vendidaEn,
    ));

    $orden = enSuComercio($vendor, fn (): Order => app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents));

    enSuComercio($vendor, function () use ($orden, $unidad, $vendidaEn, $started, $ready): void {
        $comanda = new KitchenTicket([
            'operating_unit_id' => $unidad->id,
            'order_id' => $orden->id,
            'area' => DispatchArea::Kitchen,
            'items_count' => 1,
            'started_at' => $vendidaEn->copy()->addSeconds($started),
            'ready_at' => $vendidaEn->copy()->addSeconds($ready),
        ]);

        // Pendiente → Lista de un toque es una transición legítima, y lo que
        // se prueba aquí es la lectura, no el tablero.
        $comanda->status = KitchenTicketStatus::Ready;
        $comanda->saveTransition();
    });

    test()->travelTo(test()->ahora);

    return $orden;
}
