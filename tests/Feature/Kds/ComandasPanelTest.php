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
use App\Domains\Kitchen\Actions\AdvanceKitchenTicket;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
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
use Illuminate\Support\Facades\Route;

/**
 * El tablero de comandas del organizador: todos sus comercios a la vez.
 *
 * Lo que se vigila aquí no es la consulta —de eso responde KitchenBoard y sus
 * propias pruebas— sino cuatro cosas que solo se pueden romper en esta capa:
 * que la puerta esté cerrada, que un evento ajeno no se filtre NUNCA, que el
 * 304 funcione (sin él, cada pestaña abierta se descarga el tablero entero
 * toda la noche) y que la pantalla siga siendo de SOLO LECTURA.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        // En marcha AHORA: es el que la pantalla tiene que elegir sola cuando
        // nadie le dice cuál mirar.
        $this->evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());

        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        $this->pizza = vendorIn($this->evento, 'Pizza del Malecón');

        $this->puestoTacos = outletFor($this->evento, 'Puesto Malecón', OperatingUnitKind::Mixed, $this->tacos);
        $this->puestoPizza = outletFor($this->evento, 'Horno Norte', OperatingUnitKind::Mixed, $this->pizza);

        $this->taco = platoDeComandas($this->tacos, 'Taco al pastor');
        $this->margarita = platoDeComandas($this->pizza, 'Pizza margarita');
    });

    $this->owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    $this->ref = 0;
    $this->cajas = [];

    $this->pantalla = '/event-panel/comandas';
    $this->feed = '/event-panel/comandas/feed';
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Corre algo con la cuenta y el comercio puestos, como haría una petición. */
function contextoDeComandas(Vendor $vendor, Closure $callback): mixed
{
    return app(TenantContext::class)->runAs(
        test()->organizer,
        fn () => app(VendorContext::class)->runAs($vendor, $callback),
    );
}

/** Un plato de cocina del comercio: lo justo para tener qué despachar. */
function platoDeComandas(Vendor $vendor, string $nombre): Product
{
    return contextoDeComandas($vendor, function () use ($vendor, $nombre): Product {
        $cocina = Category::create(['name' => 'Comida '.$vendor->id, 'dispatch' => DispatchArea::Kitchen]);

        return Product::create([
            'category_id' => $cocina->id,
            'name' => $nombre,
            'type' => ProductType::Simple,
            'price_cents' => 25000,
        ]);
    });
}

/** Abre la caja de un puesto la primera vez y la reutiliza después. */
function cajaDeComandas(EventOutlet $unidad, Vendor $vendor): CashSession
{
    $cajas = test()->cajas;

    if (! isset($cajas[$unidad->id])) {
        $cajas[$unidad->id] = contextoDeComandas($vendor, fn (): CashSession => app(OpenCashSession::class)($unidad, null, 0));
        test()->cajas = $cajas;
    }

    return $cajas[$unidad->id];
}

/** Vende y cobra: solo lo cobrado llega al tablero. */
function ventaDeComandas(EventOutlet $unidad, Vendor $vendor, Product $producto, int $cantidad = 1): Order
{
    $caja = cajaDeComandas($unidad, $vendor);

    return contextoDeComandas($vendor, function () use ($caja, $producto, $cantidad): Order {
        $orden = app(PlaceOrder::class)(
            $caja,
            [['product_id' => $producto->id, 'quantity' => $cantidad]],
            'pos-'.str_pad((string) ++test()->ref, 4, '0', STR_PAD_LEFT),
        );

        return app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
    });
}

/** Los nombres de comercio que devuelve el feed, en el orden en que vienen. */
function comerciosDelFeed(array $cuerpo): array
{
    return array_column($cuerpo['vendors'] ?? [], 'name');
}

/** Todas las comandas del feed, de todos los comercios y puestos. */
function comandasDelFeed(array $cuerpo): array
{
    $todas = [];

    foreach ($cuerpo['vendors'] ?? [] as $comercio) {
        foreach ($comercio['units'] ?? [] as $puesto) {
            foreach ($puesto['tickets'] ?? [] as $comanda) {
                $todas[] = $comanda;
            }
        }
    }

    return $todas;
}

it('opens the board and feeds the comandas of every vendor in the live event', function (): void {
    ventaDeComandas($this->puestoTacos, $this->tacos, $this->taco, 2);
    ventaDeComandas($this->puestoPizza, $this->pizza, $this->margarita);

    // La pantalla se abre sin decirle qué evento: el que está en marcha lo
    // resuelve ella, y lo dice.
    $this->actingAs($this->owner)
        ->get($this->pantalla)
        ->assertOk()
        ->assertSee('Comandas en vivo')
        ->assertSee('Bocao 2026')
        ->assertSee('elegido solo, por ser el que está en marcha.')
        ->assertSee('Esta pantalla es para mirar, no para operar.');

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();

    expect($cuerpo['event']['name'])->toBe('Bocao 2026')
        ->and($cuerpo['vendor'])->toBeNull()
        ->and($cuerpo['totals']['pending'])->toBe(2)
        ->and($cuerpo['totals']['open'])->toBe(2)
        ->and(comerciosDelFeed($cuerpo))->toContain('Tacos del Puerto', 'Pizza del Malecón');

    $comandas = comandasDelFeed($cuerpo);

    expect($comandas)->toHaveCount(2)
        ->and(array_column($comandas, 'status'))->each->toBe('pending')
        // El reloj lo pinta el navegador: aquí solo viajan MARCAS de tiempo.
        ->and($comandas[0]['waiting_since'])->toBeString();

    expect(collect($comandas)->pluck('lines')->flatten(1)->pluck('product_name')->all())
        ->toContain('Taco al pastor', 'Pizza margarita');
});

it('leaves the other vendors out when the board is filtered by one', function (): void {
    ventaDeComandas($this->puestoTacos, $this->tacos, $this->taco);
    ventaDeComandas($this->puestoPizza, $this->pizza, $this->margarita);

    $cuerpo = $this->actingAs($this->owner)
        ->getJson($this->feed.'?comercio='.$this->pizza->id)
        ->assertOk()
        ->json();

    expect($cuerpo['vendor']['name'])->toBe('Pizza del Malecón')
        ->and(comerciosDelFeed($cuerpo))->toBe(['Pizza del Malecón'])
        ->and($cuerpo['totals']['open'])->toBe(1);

    expect(collect(comandasDelFeed($cuerpo))->pluck('lines')->flatten(1)->pluck('product_name')->all())
        ->toBe(['Pizza margarita']);
});

it('answers 304 while nothing moves and a fresh board as soon as something does', function (): void {
    $orden = ventaDeComandas($this->puestoTacos, $this->tacos, $this->taco);

    $primera = $this->actingAs($this->owner)->getJson($this->feed)->assertOk();
    $etag = $primera->headers->get('ETag');

    expect($etag)->toBeString();

    // Nada se ha movido: el mismo tablero no se vuelve a bajar. Sin esto,
    // cada pestaña abierta en la oficina se descargaría el evento entero cada
    // cinco segundos durante toda la noche.
    $this->actingAs($this->owner)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson($this->feed)
        ->assertStatus(304);

    // Alguien la empieza EN LA TABLET, que es donde se marca.
    contextoDeComandas($this->tacos, fn () => app(AdvanceKitchenTicket::class)(
        $orden, DispatchArea::Kitchen, KitchenTicketStatus::Pending, KitchenTicketStatus::InProgress,
    ));

    $segunda = $this->actingAs($this->owner)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson($this->feed)
        ->assertOk();

    expect($segunda->headers->get('ETag'))->not->toBe($etag)
        ->and($segunda->json('totals.in_progress'))->toBe(1)
        ->and($segunda->json('totals.pending'))->toBe(0);
});

it('keeps out someone who administers events but cannot read the account numbers', function (): void {
    // La misma puerta que los tiempos: esta pantalla compara comercios entre
    // sí, y eso es lo que guarda ReportsViewTenant. Si aquí entrara alguien
    // que allí no entra, el botón «Ver las comandas en vivo» llevaría a una
    // pantalla que ese mismo usuario no puede abrir.
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

    $this->actingAs($coordinador)->get($this->pantalla)->assertForbidden();
    $this->actingAs($coordinador)->getJson($this->feed)->assertForbidden();
});

it('keeps vendor staff out of the organizer board', function (): void {
    $encargado = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::VendorManager, $this->tacos, null, 'lia',
    );

    $this->actingAs($encargado)->get($this->pantalla)->assertForbidden();
    $this->actingAs($encargado)->getJson($this->feed)->assertForbidden();
});

it('never lets an organizer see a single comanda of another account', function (): void {
    ventaDeComandas($this->puestoTacos, $this->tacos, $this->taco);

    // Otra productora entera, con su propio festival en marcha y vendiendo a
    // la misma hora. El organizador de Bocao no puede ver de ahí ni una línea,
    // ni siquiera pidiendo su evento por la URL.
    $ajena = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);

    // Todo lo de la otra cuenta se monta con SU contexto de principio a fin
    // —catálogo, puesto y venta—, que es como ocurre de verdad.
    [$eventoAjeno, $comercioAjeno] = app(TenantContext::class)->runAs($ajena, function (): array {
        $evento = app(CreateEvent::class)('Festival Ajeno', now()->subDay(), now()->addDay());
        $comercio = vendorIn($evento, 'Arepas Ajenas');
        $puesto = outletFor($evento, 'Puesto Ajeno', OperatingUnitKind::Mixed, $comercio);

        app(VendorContext::class)->runAs($comercio, function () use ($puesto): void {
            $categoria = Category::create(['name' => 'Comida ajena', 'dispatch' => DispatchArea::Kitchen]);
            $plato = Product::create([
                'category_id' => $categoria->id,
                'name' => 'Arepa de queso',
                'type' => ProductType::Simple,
                'price_cents' => 20000,
            ]);

            $caja = app(OpenCashSession::class)($puesto, null, 0);
            $orden = app(PlaceOrder::class)($caja, [['product_id' => $plato->id, 'quantity' => 1]], 'pos-ajena-1');
            app(PayOrder::class)($orden, PaymentMethod::Cash, $orden->total_cents);
        });

        return [$evento, $comercio];
    });

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();

    expect(comerciosDelFeed($cuerpo))->not->toContain('Arepas Ajenas')
        ->and($cuerpo['totals']['open'])->toBe(1);

    expect(collect(comandasDelFeed($cuerpo))->pluck('lines')->flatten(1)->pluck('product_name')->all())
        ->toBe(['Taco al pastor']);

    $this->actingAs($this->owner)
        ->get($this->pantalla)
        ->assertOk()
        ->assertDontSee('Arepas Ajenas')
        ->assertDontSee('Puesto Ajeno');

    // Y pidiendo el evento ajeno por la URL: TenantScope falla CERRADO, así
    // que ese evento sencillamente no existe para esta cuenta.
    $this->actingAs($this->owner)->get($this->pantalla.'?evento='.$eventoAjeno->id)->assertNotFound();
    $this->actingAs($this->owner)->getJson($this->feed.'?comercio='.$comercioAjeno->id)->assertNotFound();
});

it('offers no way at all to change the state of a comanda', function (): void {
    ventaDeComandas($this->puestoTacos, $this->tacos, $this->taco);

    // 1. Ninguna ruta del tablero acepta nada que no sea leer. La decisión de
    //    producto vive aquí, no en un comentario: quien marca es quien cocina,
    //    y si se marcara desde la oficina los sellos de hora dejarían de decir
    //    cuándo se hizo el plato para decir cuándo alguien pulsó.
    $metodos = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta): bool => str_starts_with((string) $ruta->uri(), 'event-panel/comandas'))
        ->flatMap(fn ($ruta): array => $ruta->methods())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($metodos)->toBe(['GET', 'HEAD']);

    $this->actingAs($this->owner)->post($this->pantalla)->assertStatus(405);

    // 2. Y el feed tampoco insinúa un botón: la tarjeta trae lo que hace falta
    //    para MIRARLA y ni un campo más. Fijar las claves aquí es lo que impide
    //    que un día se cuele un «next_status» y detrás un botón.
    $comandas = comandasDelFeed($this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json());

    expect(array_keys($comandas[0]))->toBe([
        'order_id', 'area', 'area_label', 'status', 'status_label', 'number',
        'customer_name', 'items_count', 'waiting_since', 'started_at', 'late', 'lines',
    ]);

    // 3. La pantalla lo dice con todas las letras, para que nadie tenga que
    //    deducir por qué no hay botones.
    $this->actingAs($this->owner)
        ->get($this->pantalla)
        ->assertOk()
        ->assertSee('Esta pantalla es para mirar, no para operar.')
        ->assertDontSee('Marcar lista')
        ->assertDontSee('Empezar');
});
