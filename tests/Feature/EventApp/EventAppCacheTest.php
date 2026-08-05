<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventApp\Models\EventAppManifest;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lo que hace barata la puerta pública, ahora que no tiene freno por IP.
 *
 * El limitador se quitó midiendo —con `trustProxies(at: '*')` la IP la escribe
 * quien llama, así que no contaba contra quien ataca y sí contra el festival
 * entero detrás del NAT de su operador—, y lo que quedó sosteniéndola era el
 * ETag. Pero el ETag NO AHORRA TRABAJO DE SERVIDOR: un 304 hacía exactamente
 * las mismas consultas que un 200 y solo se ahorraba los bytes. Lo que se
 * prueba aquí es lo que sí lo ahorra: que la segunda petición idéntica no
 * consulta nada, que el 304 tampoco, y que lo que se guarda vuelve intacto.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026', now()->subDay(), now()->addDay(), 'Sambil', EventStatus::Active,
        );

        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        outletFor($this->evento, 'Puesto Norte', OperatingUnitKind::Kitchen, $this->tacos);

        $this->pizzas = vendorIn($this->evento, 'Pizzas Doña Ana');

        app(VendorContext::class)->runAs($this->tacos, function (): void {
            $comida = Category::create(['name' => 'Comida', 'dispatch' => DispatchArea::Kitchen]);

            Product::create([
                'category_id' => $comida->id, 'name' => 'Taco al pastor',
                'type' => ProductType::Simple, 'price_cents' => 25000,
            ]);
        });

        app(VendorContext::class)->runAs($this->pizzas, function (): void {
            $comida = Category::create(['name' => 'Pizzas', 'dispatch' => DispatchArea::Kitchen]);

            Product::create([
                'category_id' => $comida->id, 'name' => 'Pizza margarita',
                'type' => ProductType::Simple, 'price_cents' => 40000,
            ]);
        });
    });

    $this->codigo = (string) $this->evento->public_code;
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/**
 * Cuántas consultas cuesta una petición. Se escucha solo lo de dentro para no
 * contar la preparación del test, que monta un festival entero.
 */
function consultasDe(Closure $peticion): int
{
    $consultas = 0;

    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    $peticion();

    DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

    return $consultas;
}

it('does not query the catalogue again for a second identical request', function (string $ruta, int $primera, int $puerta): void {
    $url = str_replace(
        ['{codigo}', '{comercio}'],
        [$this->codigo, (string) $this->tacos->id],
        $ruta,
    );

    // El primer teléfono paga las consultas.
    expect(consultasDe(fn () => test()->getJson($url)->assertOk()))->toBe($primera);

    // Y los siguientes solo pagan la PUERTA —evento, cuenta y, si la URL lo
    // lleva, comercio y participación—, que se revalida siempre a propósito.
    // Del cuerpo no queda ninguna: sin esto, seis mil arranques de app son
    // seis mil veces la misma consulta para la misma respuesta.
    expect(consultasDe(fn () => test()->getJson($url)->assertOk()))->toBe($puerta);

    // Y el 304, que es el caso que más se repite y el que hacía ver que el
    // ETag ahorra red pero no servidor, cuesta ahora lo mismo que un acierto
    // de caché: antes hacía exactamente las mismas consultas que un 200.
    $etag = (string) test()->getJson($url)->assertOk()->headers->get('ETag');

    expect(consultasDe(
        fn () => test()->getJson($url, ['If-None-Match' => $etag])->assertStatus(304),
    ))->toBe($puerta);
})->with([
    // Los números son los medidos, no una estimación, y son exactos a
    // propósito: una consulta nueva que se cuele en la puerta tiene que
    // romper esto y que alguien la mire. Cuentan con el store `array` de los
    // tests; con `database` cada fila sube una más, que es la lectura de la
    // propia caché.
    ['/api/event-app/eventos/{codigo}/manifiesto', 3, 2],
    ['/api/event-app/eventos/{codigo}/comercios', 5, 2],
    ['/api/event-app/eventos/{codigo}/comercios/{comercio}/menu', 8, 4],
]);

it('keeps the body intact through the cache store the project actually uses', function (): void {
    // Los tests corren con el store `array`, que no serializa nada, y eso
    // esconde el fallo entero: `CACHE_STORE` es `database` en este proyecto y
    // `config/cache.php` fija `serializable_classes => false`, así que TODO
    // objeto guardado vuelve como __PHP_Incomplete_Class. Medido: el `textos`
    // del manifiesto volvía como {"__PHP_Incomplete_Class_Name":"stdClass"} —
    // basura servida a la app en el campo que el contrato promete como
    // diccionario, y un ETag distinto en cada petición, o sea el 304 muerto.
    config()->set('cache.default', 'database');
    Cache::purge('database');

    $url = "/api/event-app/eventos/{$this->codigo}/manifiesto";

    $primera = test()->getJson($url)->assertOk();
    $segunda = test()->getJson($url)->assertOk();

    expect($segunda->json('textos'))->toBe([])
        ->and($segunda->headers->get('ETag'))->toBe($primera->headers->get('ETag'));

    // Y las otras dos, que no llevan objetos pero se guardan igual.
    foreach (["/api/event-app/eventos/{$this->codigo}/comercios",
        "/api/event-app/eventos/{$this->codigo}/comercios/{$this->tacos->id}/menu"] as $otra) {
        $uno = test()->getJson($otra)->assertOk();

        expect(test()->getJson($otra)->assertOk()->json())->toBe($uno->json());
    }
});

it('never serves one vendor the menu of the vendor next to him', function (): void {
    $tacos = test()->getJson("/api/event-app/eventos/{$this->codigo}/comercios/{$this->tacos->id}/menu")
        ->assertOk();

    $pizzas = test()->getJson("/api/event-app/eventos/{$this->codigo}/comercios/{$this->pizzas->id}/menu")
        ->assertOk();

    // El comercio entra en la llave. Sin él, la segunda carta sería la
    // primera: mismo evento, mismo endpoint, un 200 perfectamente válido con
    // los precios del competidor de al lado. Es el mismo agujero que cierra
    // el backstop de VendorScope, un piso más arriba.
    expect($tacos->json('comercio.nombre'))->toBe('Tacos del Puerto')
        ->and($pizzas->json('comercio.nombre'))->toBe('Pizzas Doña Ana')
        ->and($pizzas->json('categorias.0.productos.0.nombre'))->toBe('Pizza margarita');
});

it('cuts off a suspended vendor without waiting for any cache to expire', function (): void {
    $url = "/api/event-app/eventos/{$this->codigo}/comercios/{$this->pizzas->id}/menu";

    test()->getJson($url)->assertOk();

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->pizzas->update(['status' => VendorStatus::Suspended]);
    });

    // LA PUERTA NO SE CACHEA, y esta es la razón: aquí no hay token que
    // revocar, así que revalidar en cada petición es la única revocación que
    // existe. Una revocación cacheada es una revocación que no ocurre, y así
    // la carta se apaga en el acto aunque su cuerpo siga guardado.
    test()->getJson($url)->assertNotFound()->assertJsonPath('code', 'comercio_desconocido');
});

it('sends the guest back to a list that no longer shows the vendor that just closed', function (): void {
    $lista = "/api/event-app/eventos/{$this->codigo}/comercios";
    $carta = "/api/event-app/eventos/{$this->codigo}/comercios/{$this->pizzas->id}/menu";

    // El asistente ya vio la lista, así que está cacheada, y entró en la carta.
    test()->getJson($lista)->assertOk()->assertJsonFragment(['nombre' => 'Pizzas Doña Ana']);
    test()->getJson($carta)->assertOk();

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->pizzas->update(['status' => VendorStatus::Suspended]);
    });

    // La carta se apaga en el acto: la puerta no se cachea.
    test()->getJson($carta)->assertNotFound()->assertJsonPath('code', 'comercio_desconocido');

    // Y AQUÍ ESTABA EL BUCLE. La app manda al asistente de vuelta a la lista
    // para que vea la verdad; si la lista siguiera saliendo del caché con el
    // puesto puesto, volvería a entrar y volvería a chocar, hasta diez
    // segundos, justo en el momento en que la pantalla tenía que explicarse.
    // Suspender tira la lista de todos sus eventos, así que la vuelta enseña
    // lo que hay.
    test()->getJson($lista)->assertOk()->assertJsonMissing(['nombre' => 'Pizzas Doña Ana']);
});

it('publishes a manifest change without waiting for the window', function (): void {
    $url = "/api/event-app/eventos/{$this->codigo}/manifiesto";

    test()->getJson($url)->assertOk()->assertJsonPath('marca.nombre_app', 'Bocao 2026');

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        EventAppManifest::create(['event_id' => $this->evento->id, 'app_name' => 'Bocao']);
    });

    // El manifiesto es la única de las tres respuestas que alguien cambia
    // MIRANDO el resultado: se elige un color en el panel y se mira el
    // teléfono. Si no cambia, lo que se concluye no es «hay una caché», es
    // «el panel no guardó» — y detrás de esa conclusión viene guardar tres
    // veces más. Por eso el modelo tira su entrada al guardarse.
    test()->getJson($url)->assertOk()->assertJsonPath('marca.nombre_app', 'Bocao');
});

it('stops serving a stale catalogue once the window closes', function (): void {
    $url = "/api/event-app/eventos/{$this->codigo}/comercios";

    test()->getJson($url)->assertOk()->assertJsonFragment(['nombre' => 'Pizzas Doña Ana']);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->pizzas->update(['name' => 'Pizzería Doña Ana']);
    });

    // El precio de que la puerta sea barata, escrito como test y no como nota
    // al pie. La ventana sigue existiendo para todo lo que NO se engancha a
    // conciencia: renombrar un comercio, añadirle un puesto, tocar su catálogo.
    // Son cambios que nadie mira desde un teléfono esperando el resultado, y
    // colgarles un borrado de caché metería trabajo de la app del asistente
    // dentro del camino caliente del POS.
    test()->getJson($url)->assertOk()->assertJsonFragment(['nombre' => 'Pizzas Doña Ana']);

    $this->travel(11)->seconds();

    test()->getJson($url)->assertOk()->assertJsonFragment(['nombre' => 'Pizzería Doña Ana']);
});
