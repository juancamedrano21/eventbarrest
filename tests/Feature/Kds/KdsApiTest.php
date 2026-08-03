<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
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
use Illuminate\Testing\TestResponse;

/**
 * La API del KDS de punta a punta: el alta con PIN, el snapshot con ETag, el
 * toque que mueve la comanda y la búsqueda del «¿y lo mío?».
 *
 * Lo que se prueba aquí no es que los endpoints respondan, sino las cuatro
 * cosas que, si se rompen, se descubren en la cocina y no en el CI: que una
 * tablet solo ve lo suyo aunque el vecino esté en el mismo festival, que lo
 * ajeno responde 404 y no un 200 vacío, que el 304 de verdad ocurre —y deja
 * de ocurrir en cuanto algo se mueve— y que perder la carrera contra la otra
 * tablet devuelve la fila vigente para repintar sin otra ida y vuelta.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), null, EventStatus::Active,
        );

        $this->tacos = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($evento, $this->tacos, 1000);
        $this->norte = outletFor($evento, 'Puesto Norte', OperatingUnitKind::Mixed, $this->tacos);
        // Segundo puesto del MISMO comercio: es el caso que los scopes no
        // separan, y por tanto el único que puede colarse solo.
        $this->este = outletFor($evento, 'Puesto Este', OperatingUnitKind::Kitchen, $this->tacos);
        $this->pinNorte = app(RotateOutletKdsPin::class)($this->norte);

        $this->pizzas = app(CreateVendor::class)('Pizzas Doña Ana');
        app(InviteVendorToEvent::class)($evento, $this->pizzas, 1000);
        $this->sur = outletFor($evento, 'Puesto Sur', OperatingUnitKind::Kitchen, $this->pizzas);
        $this->pinSur = app(RotateOutletKdsPin::class)($this->sur);

        app(VendorContext::class)->runAs($this->tacos, function (): void {
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

        app(VendorContext::class)->runAs($this->pizzas, function (): void {
            $cocina = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            $this->pizza = Product::create([
                'category_id' => $cocina->id, 'name' => 'Pizza margarita',
                'type' => ProductType::Simple, 'price_cents' => 40000,
            ]);
        });
    });

    // El alta pasa por la puerta de verdad: sin cuenta activa, como una
    // tablet recién sacada de la caja.
    $this->tabletNorte = app(EnrollKdsDevice::class)(
        (string) $this->tacos->kds_code, $this->pinNorte, 'Tablet norte', null,
    );

    $this->refDeVenta = 0;
    $this->cajasAbiertas = [];
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Corre algo con la cuenta y un comercio puestos, como haría una petición. */
function enElComercioDelKds(Vendor $vendor, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs($vendor, $callback),
    );
}

/** Abre la caja de un puesto la primera vez y la reutiliza después. */
function cajaDelKds(Vendor $vendor, EventOutlet $puesto): CashSession
{
    $cajas = test()->cajasAbiertas;

    if (! isset($cajas[$puesto->id])) {
        $cajas[$puesto->id] = enElComercioDelKds(
            $vendor,
            fn (): CashSession => app(OpenCashSession::class)($puesto, null, 0),
        );
        test()->cajasAbiertas = $cajas;
    }

    return $cajas[$puesto->id];
}

/** Una venta cobrada: solo lo cobrado llega a la cocina. */
function ventaDelKds(Vendor $vendor, EventOutlet $puesto, array $lineas, ?string $cliente = null): Order
{
    $caja = cajaDelKds($vendor, $puesto);

    return enElComercioDelKds($vendor, function () use ($caja, $lineas, $cliente): Order {
        $orden = app(PlaceOrder::class)(
            $caja,
            $lineas,
            'kds-'.str_pad((string) ++test()->refDeVenta, 4, '0', STR_PAD_LEFT),
            customerName: $cliente,
        );

        return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
    });
}

/** Lo que hace la tablet cada pocos segundos, con o sin ETag guardado. */
function pedirElTablero(string $token, ?string $etag = null): TestResponse
{
    $cabeceras = $etag === null ? [] : ['If-None-Match' => $etag];

    return test()->withToken($token)->getJson('/api/kds/comandas', $cabeceras);
}

/** El toque en la tarjeta, tal cual lo manda la app. */
function tocarLaTarjeta(string $token, Order $orden, DispatchArea $area, string $desde, string $hasta): TestResponse
{
    return test()->withToken($token)->postJson(
        "/api/kds/comandas/{$orden->id}/{$area->value}/estado",
        ['from' => $desde, 'to' => $hasta],
    );
}

it('enrols a tablet over http and serves the board with the token it got', function (): void {
    $codigo = (string) $this->tacos->kds_code;

    // Tal como llega de la tablet: en minúscula y con el guion de la hoja
    // pegada en el puesto.
    $alta = $this->postJson('/api/kds/enrolar', [
        'codigo' => mb_strtolower(mb_substr($codigo, 0, 4).'-'.mb_substr($codigo, 4)),
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet ventanilla',
        'area' => null,
    ])->assertCreated();

    $alta->assertJsonPath('device.name', 'Tablet ventanilla')
        ->assertJsonPath('device.area', null)
        ->assertJsonPath('outlet.name', 'Puesto Norte')
        ->assertJsonPath('vendor.name', 'Tacos del Puerto')
        ->assertJsonPath('event.name', 'Bocao 2026');

    $token = (string) $alta->json('token');
    expect($token)->toHaveLength(64);

    $orden = ventaDelKds($this->tacos, $this->norte, [
        ['product_id' => $this->taco->id, 'quantity' => 2, 'notes' => 'Sin cebolla'],
    ], 'Marielys');

    $tablero = pedirElTablero($token)->assertOk();

    $tablero->assertJsonPath('outlet.name', 'Puesto Norte')
        ->assertJsonPath('tickets.0.order_id', $orden->id)
        ->assertJsonPath('tickets.0.area', 'kitchen')
        // Nadie la ha tocado y ya está en la pantalla: no hay fila que crear.
        ->assertJsonPath('tickets.0.status', 'pending')
        ->assertJsonPath('tickets.0.customer_name', 'Marielys')
        ->assertJsonPath('tickets.0.items_count', 2)
        ->assertJsonPath('tickets.0.lines.0.notes', 'Sin cebolla');

    // La hora del servidor viaja siempre: los relojes de la tablet se
    // calculan contra ella y no contra el suyo, que puede estar corrido.
    expect($tablero->json('server_time'))->not->toBeNull();
});

it('never serves a competitor board from the same festival', function (): void {
    $mia = ventaDelKds($this->tacos, $this->norte, [['product_id' => $this->taco->id, 'quantity' => 1]]);
    $delVecino = ventaDelKds($this->pizzas, $this->sur, [['product_id' => $this->pizza->id, 'quantity' => 1]]);

    // Misma cuenta y mismo evento: TenantScope no separa nada aquí.
    expect($mia->tenant_id)->toBe($delVecino->tenant_id);

    $tablero = pedirElTablero($this->tabletNorte->plainToken)->assertOk();

    expect(collect($tablero->json('tickets'))->pluck('order_id')->all())->toBe([$mia->id]);

    // Y tampoco puede tocarla escribiendo el id a mano.
    tocarLaTarjeta($this->tabletNorte->plainToken, $delVecino, DispatchArea::Kitchen, 'pending', 'in_progress')
        ->assertNotFound();
});

it('answers 404 for an order of another outlet instead of an empty 200', function (): void {
    // El puesto de al lado, del MISMO comercio: los scopes lo dejan pasar
    // —misma cuenta, mismo vendor— y lo único que lo para es el whereIn
    // sobre las unidades que vigila esta tablet.
    $delOtroPuesto = ventaDelKds($this->tacos, $this->este, [['product_id' => $this->taco->id, 'quantity' => 1]]);

    tocarLaTarjeta($this->tabletNorte->plainToken, $delOtroPuesto, DispatchArea::Kitchen, 'pending', 'in_progress')
        ->assertNotFound()
        ->assertJsonPath('code', 'kitchen_wrong_unit');

    // Y no se le inventó ninguna comanda por el camino.
    expect(KitchenTicket::query()->withoutTenancy()->count())->toBe(0);
});

it('shuts a revoked tablet out on the very next poll', function (): void {
    pedirElTablero($this->tabletNorte->plainToken)->assertOk();

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        app(RevokeKdsDevice::class)($this->tabletNorte->device);
    });

    pedirElTablero($this->tabletNorte->plainToken)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'kds_revocado');
});

it('lets a tablet revoke itself and stop entering', function (): void {
    $this->withToken($this->tabletNorte->plainToken)->postJson('/api/kds/salir')->assertOk();

    pedirElTablero($this->tabletNorte->plainToken)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'kds_revocado');
});

it('answers 304 while nothing changed and stops the moment a ticket advances', function (): void {
    $orden = ventaDelKds($this->tacos, $this->norte, [['product_id' => $this->taco->id, 'quantity' => 1]]);

    $token = $this->tabletNorte->plainToken;

    $primero = pedirElTablero($token)->assertOk();
    $etag = (string) $primero->headers->get('ETag');

    expect($etag)->toStartWith('W/"');

    // Nada se movió: la tablet se ahorra el tablero entero. Y esto solo
    // ocurre porque server_time NO entra en el hash — si entrara, el ETag
    // cambiaría cada segundo y este 304 no pasaría jamás.
    pedirElTablero($token, $etag)->assertStatus(304);

    // Se toca la tarjeta y el ETag tiene que dejar de valer en el acto.
    tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'pending', 'in_progress')->assertOk();

    $segundo = pedirElTablero($token, $etag)->assertOk();

    expect($segundo->headers->get('ETag'))->not->toBe($etag)
        ->and($segundo->json('tickets.0.status'))->toBe('in_progress')
        ->and($segundo->json('tickets.0.started_at'))->not->toBeNull();
});

it('answers 409 with the current row when the from is already stale', function (): void {
    $orden = ventaDelKds($this->tacos, $this->norte, [['product_id' => $this->taco->id, 'quantity' => 1]]);

    $token = $this->tabletNorte->plainToken;

    tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'pending', 'in_progress')->assertOk();

    // La segunda tablet tenía la pantalla de hace tres segundos y cree que
    // sigue pendiente. Sin este 409 desharía el trabajo de la primera sin
    // enterarse, porque volver atrás es un movimiento legal.
    $choque = tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'pending', 'ready')
        ->assertStatus(409)
        ->assertJsonPath('code', 'kitchen_status_changed');

    // Y la fila vigente viaja dentro: la tarjeta se repinta sin otra vuelta.
    $choque->assertJsonPath('ticket.order_id', $orden->id)
        ->assertJsonPath('ticket.area', 'kitchen')
        ->assertJsonPath('ticket.status', 'in_progress');

    expect($choque->json('ticket.started_at'))->not->toBeNull();
});

it('walks a ticket forward and back through the endpoint', function (): void {
    $orden = ventaDelKds($this->tacos, $this->norte, [['product_id' => $this->taco->id, 'quantity' => 1]]);

    $token = $this->tabletNorte->plainToken;

    tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'pending', 'in_progress')->assertOk();
    tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'in_progress', 'ready')
        ->assertOk()
        ->assertJsonPath('ticket.status', 'ready');

    // El dedazo en una pantalla grasienta existe: se puede volver, y volver
    // borra la marca que se deshace.
    $vuelta = tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'ready', 'in_progress')
        ->assertOk()
        ->assertJsonPath('ticket.status', 'in_progress');

    expect($vuelta->json('ticket.ready_at'))->toBeNull()
        // Quien empezó, empezó: eso no se deshace.
        ->and($vuelta->json('ticket.started_at'))->not->toBeNull();

    // Dos pasos atrás de golpe no es un dedazo: no existe ese movimiento.
    tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'ready', 'pending')->assertStatus(409);
});

it('finds a customer order that already fell off the board', function (): void {
    $orden = ventaDelKds($this->tacos, $this->norte, [
        ['product_id' => $this->taco->id, 'quantity' => 3],
    ], 'Marielys');

    $token = $this->tabletNorte->plainToken;

    tocarLaTarjeta($token, $orden, DispatchArea::Kitchen, 'pending', 'ready')->assertOk();

    // Media hora después la tarjeta se cayó sola del tablero: Lista es
    // terminal y la columna no puede crecer toda la noche.
    enElComercioDelKds($this->tacos, function () use ($orden): void {
        $comanda = KitchenTicket::query()->where('order_id', $orden->id)->sole();
        $comanda->ready_at = now()->subMinutes(30);
        $comanda->save();
    });

    expect(pedirElTablero($token)->assertOk()->json('tickets'))->toBe([]);

    // Y aun así, cuando la clienta viene a preguntar, hay una respuesta con
    // hora dentro: «lista a las 8:14» cierra la conversación.
    $porNombre = $this->withToken($token)->getJson('/api/kds/buscar?q=marie')->assertOk();

    $porNombre->assertJsonPath('results.0.order_id', $orden->id)
        ->assertJsonPath('results.0.customer_name', 'Marielys')
        ->assertJsonPath('results.0.areas.0.status', 'ready')
        ->assertJsonPath('results.0.areas.0.items_count', 3);

    expect($porNombre->json('results.0.areas.0.ready_at'))->not->toBeNull();

    // Y por el número cantado como sea: «el V0001», «el 1», «el 0001».
    $numero = (string) $orden->publicNumber();

    $this->withToken($token)->getJson('/api/kds/buscar?q='.urlencode($numero))
        ->assertOk()
        ->assertJsonPath('results.0.order_id', $orden->id);

    // Lo del puesto de al lado no sale, aunque sea del mismo comercio.
    $delOtroPuesto = ventaDelKds($this->tacos, $this->este, [
        ['product_id' => $this->taco->id, 'quantity' => 1],
    ], 'Marielys Ajena');

    expect(collect($this->withToken($token)->getJson('/api/kds/buscar?q=Marielys')->json('results'))
        ->pluck('order_id')->all())->not->toContain($delOtroPuesto->id);
});

it('counts only the failures when braking the enrolment', function (): void {
    $codigo = (string) $this->tacos->kds_code;

    // Cuatro dedazos seguidos y un acierto: el acierto LIMPIA la cuenta.
    // Con el patrón del POS —contar también los aciertos— la sexta tablet
    // de un montaje recibiría 429 sin que nadie se hubiera equivocado.
    for ($intento = 1; $intento <= 4; $intento++) {
        $this->postJson('/api/kds/enrolar', [
            'codigo' => $codigo, 'pin' => '000000', 'device_name' => 'Tablet', 'area' => null,
        ])->assertStatus(422)->assertJsonPath('code', 'kds_enrollment_rejected');
    }

    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo, 'pin' => $this->pinNorte, 'device_name' => 'Tablet 5', 'area' => 'kitchen',
    ])->assertCreated()->assertJsonPath('device.area', 'kitchen');

    // Y la siguiente tablet del montaje entra igual de bien.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => $codigo, 'pin' => $this->pinNorte, 'device_name' => 'Tablet 6', 'area' => null,
    ])->assertCreated();
});

it('brakes a run of wrong pins with a 429', function (): void {
    $codigo = (string) $this->tacos->kds_code;

    for ($intento = 1; $intento <= 5; $intento++) {
        $this->postJson('/api/kds/enrolar', [
            'codigo' => $codigo, 'pin' => '000000', 'device_name' => 'Tablet', 'area' => null,
        ])->assertStatus(422);
    }

    // El sexto ni siquiera llega a comprobar el PIN. Y da igual que se
    // escriba el código con guion: la llave se normaliza igual que dentro.
    $this->postJson('/api/kds/enrolar', [
        'codigo' => mb_substr($codigo, 0, 4).'-'.mb_substr($codigo, 4),
        'pin' => $this->pinNorte,
        'device_name' => 'Tablet',
        'area' => null,
    ])->assertStatus(429)->assertJsonPath('code', 'kds_demasiados_intentos');
});

it('splits a mixed sale into two cards and refuses a foreign area to a bar tablet', function (): void {
    $orden = ventaDelKds($this->tacos, $this->norte, [
        ['product_id' => $this->taco->id, 'quantity' => 1],
        ['product_id' => $this->refresco->id, 'quantity' => 2],
    ]);

    // Una tablet enrolada solo para la barra: ve su mitad y nada más.
    $barra = app(EnrollKdsDevice::class)(
        (string) $this->tacos->kds_code, $this->pinNorte, 'Tablet barra', DispatchArea::Bar,
    );

    $tablero = pedirElTablero($barra->plainToken)->assertOk();

    expect(collect($tablero->json('tickets'))->pluck('area')->all())->toBe(['bar']);

    // Y la mitad de cocina no la puede tocar: lo que no sale en tu tablero
    // no existe para ti.
    tocarLaTarjeta($barra->plainToken, $orden, DispatchArea::Kitchen, 'pending', 'ready')
        ->assertNotFound()
        ->assertJsonPath('code', 'kds_area_ajena');

    // La suya sí, y la de cocina se entera de cómo va su hermana.
    tocarLaTarjeta($barra->plainToken, $orden, DispatchArea::Bar, 'pending', 'ready')->assertOk();

    $cocina = pedirElTablero($this->tabletNorte->plainToken)->assertOk();

    expect(collect($cocina->json('tickets'))->firstWhere('area', 'kitchen')['sibling_status'])->toBe('ready');
});

it('refuses the board to a request with no token at all', function (): void {
    $this->getJson('/api/kds/comandas')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'kds_sin_token');
});
