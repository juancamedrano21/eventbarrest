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
use App\Domains\Identity\Actions\ApplyRoleTemplates;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Enums\RoleKind;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KitchenTicket;
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
 * La pantalla de tiempos del organizador.
 *
 * Lo que se vigila aquí no son los segundos —de eso responde KitchenTimings y
 * sus propias pruebas— sino la PUERTA y lo que la pantalla dice: que solo
 * entre quien puede comparar comercios, que un evento ajeno no exista, y que
 * los tiempos de un evento no se cuelen en el informe de otro.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());
        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        $this->puesto = outletFor($this->evento, 'Puesto Malecón', OperatingUnitKind::Mixed, $this->tacos);
        $this->plato = platoDeTiempos($this->tacos);
    });

    $this->owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    $this->ref = 0;
    $this->cajas = [];

    // Todo cuelga de este instante: las ventas se fabrican hacia adelante
    // desde aquí y caen dentro de la ventana del evento.
    $this->base = now()->subHours(2);

    $this->ruta = "/event-panel/eventos/{$this->evento->id}/tiempos";
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Corre algo con la cuenta y el comercio puestos, como haría una petición. */
function contextoDeTiempos(Vendor $vendor, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs($vendor, $callback),
    );
}

/** Un plato de cocina para el comercio: lo justo para tener qué cronometrar. */
function platoDeTiempos(Vendor $vendor): Product
{
    return contextoDeTiempos($vendor, function () use ($vendor): Product {
        $cocina = Category::create(['name' => 'Comida '.$vendor->id, 'dispatch' => DispatchArea::Kitchen]);

        return Product::create([
            'category_id' => $cocina->id,
            'name' => 'Plato '.$vendor->id,
            'type' => ProductType::Simple,
            'price_cents' => 25000,
        ]);
    });
}

/** Abre la caja de un puesto la primera vez y la reutiliza después. */
function cajaDeTiempos(EventOutlet $unidad, Vendor $vendor): CashSession
{
    $cajas = test()->cajas;

    if (! isset($cajas[$unidad->id])) {
        $cajas[$unidad->id] = contextoDeTiempos($vendor, fn (): CashSession => app(OpenCashSession::class)($unidad, null, 0));
        test()->cajas = $cajas;
    }

    return $cajas[$unidad->id];
}

/**
 * Una venta con sus marcas puestas al segundo, como en KitchenTimingsTest:
 * `$red` es lo que tardó el POS en sincronizar, y `$started`/`$ready` se
 * cuentan desde que el servidor se enteró. Los dos en null significan que
 * nadie tocó la comanda y ni siquiera hay fila que contar.
 */
function ventaDeTiempos(
    EventOutlet $unidad,
    Vendor $vendor,
    Product $producto,
    CarbonInterface $vendidaEn,
    int $red = 0,
    ?int $started = null,
    ?int $ready = null,
): Order {
    $caja = cajaDeTiempos($unidad, $vendor);
    $cobradaEn = $vendidaEn->copy()->addSeconds($red);

    // PlaceOrder solo se cree la hora del cajero si es verosímil desde
    // «ahora», así que hay que viajar a cada momento en vez de escribir las
    // columnas a mano.
    test()->travelTo($vendidaEn);

    $orden = contextoDeTiempos($vendor, fn (): Order => app(PlaceOrder::class)(
        $caja,
        [['product_id' => $producto->id, 'quantity' => 1]],
        'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
        soldAt: $vendidaEn,
    ));

    test()->travelTo($cobradaEn);

    $orden = contextoDeTiempos($vendor, fn (): Order => app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents));

    if ($started !== null || $ready !== null) {
        contextoDeTiempos($vendor, function () use ($orden, $unidad, $cobradaEn, $started, $ready): void {
            $comanda = new KitchenTicket([
                'operating_unit_id' => $unidad->id,
                'order_id' => $orden->id,
                'area' => DispatchArea::Kitchen,
                'items_count' => 1,
                'started_at' => $started === null ? null : $cobradaEn->copy()->addSeconds($started),
                'ready_at' => $ready === null ? null : $cobradaEn->copy()->addSeconds($ready),
            ]);

            $comanda->status = $ready === null ? KitchenTicketStatus::InProgress : KitchenTicketStatus::Ready;
            $comanda->saveTransition();
        });
    }

    test()->travelBack();

    return $orden;
}

/** Cinco comandas iguales: el mínimo para que el informe se atreva a hablar. */
function cincoComandasDeTiempos(EventOutlet $unidad, Vendor $vendor, Product $plato, int $red, int $started, int $ready): void
{
    foreach (range(0, 4) as $i) {
        ventaDeTiempos(
            $unidad, $vendor, $plato,
            test()->base->copy()->addMinutes($i),
            red: $red, started: $started, ready: $ready,
        );
    }
}

it('opens the timings screen with the four stretches named', function (): void {
    cincoComandasDeTiempos($this->puesto, $this->tacos, $this->plato, red: 30, started: 60, ready: 360);

    $this->actingAs($this->owner)
        ->get($this->ruta)
        ->assertOk()
        ->assertSee('Tiempos de despacho')
        // Los cuatro tramos con su nombre, el de la red incluido.
        ->assertSee('Espera del cliente')
        ->assertSee('En cola')
        ->assertSee('Preparando')
        ->assertSee('Retraso de sincronización')
        // El p90 no se publica a secas: se publica explicado.
        ->assertSee('una de cada diez esperó más de esto')
        ->assertSee('Tacos del Puerto')
        ->assertSee('Puesto Malecón');
});

it('says there is nothing to time instead of drawing empty cards', function (): void {
    $this->actingAs($this->owner)
        ->get($this->ruta)
        ->assertOk()
        ->assertSee('No hay ninguna comanda en este rango.')
        ->assertDontSee('una de cada diez esperó más de esto');
});

it('says out loud when the wait is the network and not the kitchen', function (): void {
    // Diez minutos sin cobertura y una cocina que despacha en dos: el
    // veredicto tiene que apuntar al wifi, no a la gente del puesto.
    cincoComandasDeTiempos($this->puesto, $this->tacos, $this->plato, red: 600, started: 60, ready: 180);

    $this->actingAs($this->owner)
        ->get($this->ruta)
        ->assertOk()
        ->assertSee('Esto no es la cocina: es la cobertura.')
        ->assertDontSee('Dónde se va la espera');
});

it('shows what is still open next to the times it is missing from', function (): void {
    cincoComandasDeTiempos($this->puesto, $this->tacos, $this->plato, red: 0, started: 0, ready: 120);

    // Y la peor de todas: la que nadie tocó jamás, que ni fila tiene.
    ventaDeTiempos($this->puesto, $this->tacos, $this->plato, $this->base->copy()->addMinutes(7));

    $this->actingAs($this->owner)
        ->get($this->ruta)
        ->assertOk()
        ->assertSee('sin cerrar')
        ->assertSee('sale con tiempos perfectos');
});

it('cuts the day in the country clock when asked for today', function (): void {
    $medianoche = today(config('app.business_timezone'));

    $this->actingAs($this->owner)
        ->get($this->ruta.'?rango=hoy')
        ->assertOk()
        // La ventana empieza a medianoche EN RD, no en UTC.
        ->assertSee($medianoche->format('d/m/Y').', 00:00');
});

it('never mixes the timings of one event into another', function (): void {
    cincoComandasDeTiempos($this->puesto, $this->tacos, $this->plato, red: 0, started: 0, ready: 120);

    [$otroPuesto, $otroComercio, $otroPlato] = app(TenantContext::class)->runAs($this->organizer, function (): array {
        $otroEvento = app(CreateEvent::class)('Bocao Invierno', now()->subDay(), now()->addDay());
        $comercio = vendorIn($otroEvento, 'Pizza del Malecón');

        return [
            outletFor($otroEvento, 'Puesto Invierno', OperatingUnitKind::Mixed, $comercio),
            $comercio,
            platoDeTiempos($comercio),
        ];
    });

    cincoComandasDeTiempos($otroPuesto, $otroComercio, $otroPlato, red: 0, started: 0, ready: 1200);

    $this->actingAs($this->owner)
        ->get($this->ruta)
        ->assertOk()
        ->assertSee('Tacos del Puerto')
        ->assertDontSee('Pizza del Malecón')
        ->assertDontSee('Puesto Invierno');
});

it('never opens an event of another account', function (): void {
    $ajena = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);

    $evento = app(TenantContext::class)->runAs(
        $ajena,
        fn () => app(CreateEvent::class)('Festival Ajeno', now()->subDay(), now()->addDay()),
    );

    $this->actingAs($this->owner)
        ->get("/event-panel/eventos/{$evento->id}/tiempos")
        ->assertNotFound();
});

it('keeps out someone who administers events but cannot read the account numbers', function (): void {
    // Organizar el festival no es leer los tiempos de la gente de otro: eso
    // lo guarda ReportsViewTenant, igual que el dinero del dashboard.
    $plantilla = RoleTemplate::query()->create([
        'label' => 'Coordinador',
        'description' => 'Organiza el evento, pero no lee los números de la cuenta.',
        'permissions' => ['events.manage'],
    ]);
    $plantilla->forceFill(['name' => 'coordinador', 'kind' => RoleKind::Account->value])->save();
    app(ApplyRoleTemplates::class)();

    $coordinador = app(CreateTenantUser::class)(
        $this->organizer, 'Beto', 'beto@x.test', 'Secreta-2026', 'coordinador',
    );

    $this->actingAs($coordinador)->get($this->ruta)->assertForbidden();
});

it('keeps vendor staff out of the organizer screen', function (): void {
    $encargado = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::VendorManager, $this->tacos, null, 'lia',
    );

    $this->actingAs($encargado)->get($this->ruta)->assertForbidden();
});
