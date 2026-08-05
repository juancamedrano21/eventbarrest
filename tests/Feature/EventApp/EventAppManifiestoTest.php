<?php

declare(strict_types=1);

use App\Domains\EventApp\Models\EventAppManifest;
use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Lo primero que pide la app al arrancar. Lo que se prueba aquí no es que el
 * endpoint conteste, sino las cuatro cosas que, si se rompen, se descubren
 * en el teléfono de un asistente y no en el CI: que un evento sin configurar
 * devuelve algo VÁLIDO en vez de un 404, que el contexto sale del evento de
 * la URL y no de una sesión que no existe —o el manifiesto saldría con la
 * marca de fábrica de un evento que sí tenía marca—, que un código que no es
 * de nadie contesta 404 y no un 200 vacío, y que el 304 de verdad ocurre.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento = app(CreateEvent::class)(
            'Bocao 2026',
            now()->setTime(20, 0),
            now()->addDays(2)->setTime(2, 0),
            'Sambil Santo Domingo',
            EventStatus::Active,
        );
    });
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Lo que hace la app al arrancar, con o sin ETag guardado. */
function pedirElManifiesto(string $codigo, ?string $etag = null): TestResponse
{
    $cabeceras = $etag === null ? [] : ['If-None-Match' => $etag];

    return test()->getJson("/api/event-app/eventos/{$codigo}/manifiesto", $cabeceras);
}

it('gives a brand new event a public code without anyone asking', function (): void {
    expect($this->evento->public_code)->toBeString()->toHaveLength(8);

    // El alfabeto dictable: sin O, sin 0, sin I, sin 1, sin L.
    expect($this->evento->public_code)->toMatch('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/');
});

it('serves a valid manifest for an event nobody has configured yet', function (): void {
    $codigo = (string) $this->evento->public_code;

    $respuesta = pedirElManifiesto($codigo)->assertOk();

    $respuesta->assertJsonPath('evento.codigo', $codigo)
        ->assertJsonPath('evento.nombre', 'Bocao 2026')
        ->assertJsonPath('evento.lugar', 'Sambil Santo Domingo')
        ->assertJsonPath('evento.estado', 'activo')
        // Sin fila de manifiesto, el nombre de la app es el del evento y los
        // colores son los de fábrica: la app arranca igual.
        ->assertJsonPath('marca.nombre_app', 'Bocao 2026')
        ->assertJsonPath('marca.color_primario', '#1A1A1A')
        ->assertJsonPath('marca.logo_url', null)
        ->assertJsonPath('marca.fuente_titulos', null)
        // Y con el único módulo que este servidor sabe servir.
        ->assertJsonPath('modulos.0.clave', 'menus')
        ->assertJsonPath('modulos.0.activo', true);

    // La hora del servidor viaja siempre: los relojes del teléfono se
    // calculan contra ella y no contra el suyo.
    expect($respuesta->json('server_time'))->not->toBeNull();

    // En la zona del negocio, no en UTC: un festival de Santo Domingo que
    // abriera a medianoche UTC diría que empieza a las cuatro de la tarde.
    expect($respuesta->json('evento.empieza_en'))->toEndWith('-04:00');
});

it('publishes the brand and the modules the organiser configured', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        EventAppManifest::create([
            'event_id' => $this->evento->id,
            'app_name' => 'Bocao',
            'primary_color' => '#f25c05',
            'heading_font' => 'Gliker',
            'modules' => [
                ['clave' => 'mapa', 'titulo' => 'Mapa', 'orden' => 2, 'activo' => false],
                ['clave' => 'menus', 'titulo' => 'Menús', 'orden' => 1, 'activo' => true],
            ],
            'texts' => ['saludo' => 'Bienvenido a Bocao'],
        ]);
    });

    $respuesta = pedirElManifiesto((string) $this->evento->public_code)->assertOk();

    $respuesta->assertJsonPath('marca.nombre_app', 'Bocao')
        // Normalizado a mayúscula: la app compara cadenas.
        ->assertJsonPath('marca.color_primario', '#F25C05')
        ->assertJsonPath('marca.fuente_titulos', 'Gliker')
        // Lo que no se configuró sigue teniendo su valor de fábrica.
        ->assertJsonPath('marca.color_fondo', '#FFFFFF')
        ->assertJsonPath('textos.saludo', 'Bienvenido a Bocao')
        // Ordenados por el servidor: dos versiones de la app no pueden
        // enseñar el menú en sitios distintos.
        ->assertJsonPath('modulos.0.clave', 'menus')
        ->assertJsonPath('modulos.1.clave', 'mapa')
        ->assertJsonPath('modulos.1.activo', false);
});

it('drops a broken module instead of shipping it to the phone', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        EventAppManifest::create([
            'event_id' => $this->evento->id,
            // Un color que no es un color y un módulo sin clave: los dos
            // caben en la base y ninguno puede llegar al teléfono.
            'primary_color' => 'naranja',
            'modules' => [
                ['titulo' => 'Sin clave', 'orden' => 1, 'activo' => true],
                ['clave' => 'menus', 'titulo' => 'Menús', 'orden' => 1, 'activo' => true],
            ],
        ]);
    });

    $respuesta = pedirElManifiesto((string) $this->evento->public_code)->assertOk();

    $respuesta->assertJsonPath('marca.color_primario', '#1A1A1A')
        ->assertJsonCount(1, 'modulos')
        ->assertJsonPath('modulos.0.clave', 'menus');
});

it('still boots the app when the manifest json got corrupted', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        EventAppManifest::create([
            'event_id' => $this->evento->id,
            'app_name' => 'Bocao',
        ]);
    });

    // Un escalar donde se espera una lista. Cabe en la columna JSON y lo deja
    // ahí un import, un seeder o un UPDATE a mano; recorrerlo era un 500 en
    // el ÚNICO endpoint sin el cual la app no puede pintarse, así que un
    // manifiesto corrupto apagaba la app de ese festival entero.
    DB::table('event_app_manifests')->where('event_id', $this->evento->id)->update([
        'modules' => json_encode('menus'),
        'texts' => json_encode('hola'),
    ]);

    $respuesta = pedirElManifiesto((string) $this->evento->public_code)->assertOk();

    // 200 degradado: lo que no está roto se sirve, y lo roto vuelve a lo de
    // fábrica. La app arranca.
    $respuesta->assertJsonPath('marca.nombre_app', 'Bocao')
        ->assertJsonCount(1, 'modulos')
        ->assertJsonPath('modulos.0.clave', 'menus');

    expect($respuesta->json('textos'))->toBe([]);
});

it('publishes an empty module list when that is what was saved', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        EventAppManifest::create([
            'event_id' => $this->evento->id,
            'modules' => [],
        ]);
    });

    $respuesta = pedirElManifiesto((string) $this->evento->public_code)->assertOk();

    // La lista manda, y una lista vacía es una decisión: una app que arranca
    // y no enseña ninguna pantalla. Se escribe aquí para que el día que el
    // formulario del panel la deje vacía sin querer, esto sea un test rojo y
    // no una sorpresa en el teléfono. Nulo es otra cosa —«no lo ha tocado
    // nadie»— y sí lleva a los módulos de fábrica.
    expect($respuesta->json('modulos'))->toBe([]);
});

it('serves a 304 to the forms of If-None-Match a real client sends', function (): void {
    $codigo = (string) $this->evento->public_code;

    $etag = (string) pedirElManifiesto($codigo)->assertOk()->headers->get('ETag');

    // El comodín, que es como revalida quien pregunta «¿sigue habiendo algo?»
    // sin recordar qué tenía. Casa con cualquier representación existente.
    pedirElManifiesto($codigo, '*')->assertStatus(304);

    // El mismo ETag sin la marca de débil, que es lo que deja un
    // intermediario o un cliente que normaliza: la comparación de un GET
    // condicional es débil, así que es la MISMA representación.
    pedirElManifiesto($codigo, str_replace('W/', '', $etag))->assertStatus(304);

    // Y en lista, que es como lo manda un cliente que guarda varias. Cada una
    // de estas tres se bajaba el manifiesto entero, que es justo lo que el
    // ETag venía a ahorrar en la red saturada de un recinto.
    pedirElManifiesto($codigo, '"de-otra-version", '.$etag)->assertStatus(304);
});

it('resolves the code however the app spells it', function (): void {
    $codigo = (string) $this->evento->public_code;

    // Con guiones y en minúscula, como lo teclea alguien en la pantalla de
    // depuración durante el montaje.
    $tecleado = mb_strtolower(mb_substr($codigo, 0, 4).'-'.mb_substr($codigo, 4));

    pedirElManifiesto($tecleado)->assertOk()
        // Y devuelve la forma canónica, no la que vino en la URL.
        ->assertJsonPath('evento.codigo', $codigo);
});

it('answers 404 for a code nobody owns instead of an empty 200', function (): void {
    pedirElManifiesto('NOEXISTE')
        ->assertNotFound()
        ->assertJsonPath('code', 'evento_desconocido');
});

it('hides an event that is still a draft', function (): void {
    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento->update(['status' => EventStatus::Draft]);
    });

    // Mismo cuerpo que un código inventado: quien prueba códigos a mano no
    // puede averiguar qué está montando el organizador.
    pedirElManifiesto((string) $this->evento->public_code)
        ->assertNotFound()
        ->assertJsonPath('code', 'evento_desconocido');
});

it('keeps serving an event that already finished', function (EventStatus $estado, string $publicado): void {
    app(TenantContext::class)->runAs($this->organizador, function () use ($estado): void {
        $this->evento->update(['status' => $estado]);
    });

    // Seis mil teléfonos siguen teniendo la app instalada el lunes: apagar
    // la puerta al cerrar convertiría todas esas pantallas en un error. Los
    // DOS estados terminados, no solo el primero: liquidar es una operación
    // de dinero del organizador, y no puede ser además el interruptor que
    // apaga la app de quien todavía la tiene abierta. `estado` dice la verdad
    // y la app decide qué hacer con ella; el contrato lo dice ya así.
    pedirElManifiesto((string) $this->evento->public_code)->assertOk()
        ->assertJsonPath('evento.estado', $publicado);
})->with([
    [EventStatus::Closed, 'cerrado'],
    [EventStatus::Settled, 'liquidado'],
]);

it('goes dark when the organiser account is suspended', function (): void {
    $this->organizador->update(['status' => TenantStatus::Suspended]);

    // Sin cuenta no hay contexto, y sin contexto TenantScope emitiría
    // `where 1 = 0`: el 404 es lo que impide el 200 con todo vacío.
    pedirElManifiesto((string) $this->evento->public_code)
        ->assertNotFound()
        ->assertJsonPath('code', 'evento_desconocido');
});

it('serves a 304 while nothing changes and stops the moment something does', function (): void {
    $codigo = (string) $this->evento->public_code;

    $primera = pedirElManifiesto($codigo)->assertOk();
    $etag = $primera->headers->get('ETag');

    expect($etag)->not->toBeNull();
    expect($primera->headers->get('Cache-Control'))->toContain('no-cache');

    // El 304 ocurre de verdad: server_time cambió entre las dos llamadas y
    // el ETag NO, porque no entra en el hash. Es la trampa que ya mordió en
    // el KDS y la que decide si el 304 sirve de algo.
    $this->travel(2)->seconds();

    pedirElManifiesto($codigo, (string) $etag)->assertStatus(304);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        EventAppManifest::create([
            'event_id' => $this->evento->id,
            'app_name' => 'Bocao',
        ]);
    });

    $tras = pedirElManifiesto($codigo, (string) $etag)->assertOk();

    expect($tras->headers->get('ETag'))->not->toBe($etag);
});

it('never lets one organiser hand out another one code', function (): void {
    $otro = app(CreateTenant::class)('Otro Organizador', null, TenantType::Organizer);

    $ajeno = app(TenantContext::class)->runAs($otro, fn (): Event => app(CreateEvent::class)(
        'Otro Festival', now(), now()->addDay(), null, EventStatus::Active,
    ));

    // El índice único es GLOBAL: el código identifica el evento sin decir de
    // qué cuenta es, así que dos cuentas no pueden repartir el mismo.
    expect($ajeno->public_code)->not->toBe($this->evento->public_code);

    // Y cada código lleva a SU evento, aunque la petición no traiga cuenta.
    pedirElManifiesto((string) $ajeno->public_code)->assertOk()
        ->assertJsonPath('evento.nombre', 'Otro Festival');
});
