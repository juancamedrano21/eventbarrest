<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * La batería de las tabletas donde el organizador ya está mirando.
 *
 * Lo que se vigila aquí no es que un número llegue —de eso responde
 * BateriaTest, que cubre el camino desde el navegador de la tablet— sino las
 * maneras que tiene esta pantalla de mentir la noche del festival: pintar como
 * agotada una tablet que nunca dijo nada, mandar a alguien con un cable a un
 * puesto donde ya hay uno puesto, mandarlo a un puesto donde la pantalla ya no
 * está, esconder la tablet moribunda del puesto tranquilo porque ese puesto no
 * tiene cola, y matar el 304 —que es lo que hace que este sondeo de cinco
 * segundos sea barato— al meter la batería en el cuerpo.
 *
 * LA CUENTA DE «HAY QUE LLEVAR UN CABLE» NO ESTÁ EN EL CUERPO, y esa ausencia
 * es lo que fija la mitad de este archivo. Esa frase quiere decir dos cosas a
 * la vez —que el nivel está bajo y que la tablet sigue ahí— y la segunda es
 * una resta contra el reloj de quien mira, la misma que no puede viajar sin
 * matar el 304. Así que lo que se prueba aquí es que el cuerpo lleva los HECHOS
 * con los que el navegador hace esa cuenta —el nivel, el cable, cuándo se midió
 * y cuándo se supo de la tablet— y que no lleva ningún total que la congele.
 */
beforeEach(function (): void {
    $this->organizer = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->evento = app(CreateEvent::class)('Bocao 2026', now()->subDay(), now()->addDay());

        $this->tacos = vendorIn($this->evento, 'Tacos del Puerto');
        $this->pizza = vendorIn($this->evento, 'Pizza del Malecón');

        $this->puestoTacos = outletFor($this->evento, 'Puesto Malecón', OperatingUnitKind::Mixed, $this->tacos);
        $this->puestoPizza = outletFor($this->evento, 'Horno Norte', OperatingUnitKind::Mixed, $this->pizza);

        $this->pinTacos = app(RotateOutletKdsPin::class)($this->puestoTacos);
        $this->pinPizza = app(RotateOutletKdsPin::class)($this->puestoPizza);
    });

    $this->owner = app(CreateTenantUser::class)(
        $this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner,
    );

    $this->feed = '/event-panel/comandas/feed';
    $this->pantalla = '/event-panel/comandas';
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** Cuelga una tablet en el puesto, como se hace de verdad: código y PIN. */
function tabletDelPanel(string $codigo, string $pin, string $nombre): KdsDevice
{
    return app(EnrollKdsDevice::class)($codigo, $pin, $nombre, null)->device;
}

/**
 * Deja escrita una lectura de batería como la habría dejado el middleware.
 *
 * Se escribe con un update sin scopes y no con el modelo a propósito: lo que
 * este archivo prueba es qué hace el PANEL con lo que hay en la fila, y montar
 * el camino entero de la tablet aquí solo serviría para que estas pruebas
 * fallaran el día que cambie una cabecera que no es cosa suya.
 *
 * El latido se escribe junto a la medida y por defecto es de ahora mismo: una
 * lectura de batería llega DENTRO de un sondeo, así que una fila con nivel y
 * sin latido no existe en la realidad y no debe existir aquí.
 */
function bateriaEnLaFila(
    KdsDevice $device,
    ?int $nivel,
    ?bool $cargando = false,
    int $haceSegundos = 0,
    ?int $vistoHaceSegundos = null,
): void {
    KdsDevice::query()->withoutGlobalScopes()->whereKey($device->id)->update([
        'battery_percent' => $nivel,
        'battery_charging' => $cargando,
        'battery_at' => $nivel === null ? null : now()->subSeconds($haceSegundos),
        'last_seen_at' => now()->subSeconds($vistoHaceSegundos ?? $haceSegundos),
    ]);
}

/** El bloque de un comercio dentro del feed, buscado por su nombre. */
function comercioConTabletas(array $cuerpo, string $nombre): array
{
    foreach ($cuerpo['vendors'] ?? [] as $comercio) {
        if ($comercio['name'] === $nombre) {
            return $comercio;
        }
    }

    throw new RuntimeException("El feed no trae ningún comercio llamado {$nombre}.");
}

/** Una tablet concreta dentro del bloque de su comercio. */
function tabletaDelFeed(array $comercio, string $nombre): array
{
    foreach ($comercio['tablets'] ?? [] as $tableta) {
        if ($tableta['name'] === $nombre) {
            return $tableta;
        }
    }

    throw new RuntimeException("El comercio no trae ninguna tablet llamada {$nombre}.");
}

it('brings every tablet of the stall into the feed with its level and when it was measured', function (): void {
    $ventanilla = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet ventanilla');
    $plancha = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet plancha');

    bateriaEnLaFila($ventanilla, 87, true);
    bateriaEnLaFila($plancha, 14, false, haceSegundos: 30);

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();

    $comercio = comercioConTabletas($cuerpo, 'Tacos del Puerto');

    expect($comercio['tablets'])->toHaveCount(2);

    $arriba = tabletaDelFeed($comercio, 'Tablet ventanilla');

    expect($arriba['percent'])->toBe(87)
        ->and($arriba['charging'])->toBeTrue()
        ->and($arriba['unit_name'])->toBe('Puesto Malecón')
        // La MARCA de tiempo, nunca «hace 4 minutos»: si el segundo
        // transcurrido viajara en el cuerpo, el hash cambiaría cada segundo y
        // el 304 de abajo no ocurriría jamás.
        ->and($arriba['measured_at'])->toBeString()
        ->and($arriba['low'])->toBeFalse();

    expect(tabletaDelFeed($comercio, 'Tablet plancha')['low'])->toBeTrue();

    // Y los umbrales viajan con el cuerpo, para que la pantalla no lleve
    // copiada una regla que vive en el servidor.
    expect($cuerpo['battery']['low'])->toBe(KdsDevice::BATERIA_EN_APUROS)
        ->and($cuerpo['battery']['critical'])->toBe(10)
        ->and($cuerpo['battery']['stale_seconds'])->toBe(300);
});

it('shows the tablets of a stall with nothing pending at all', function (): void {
    // El puesto tranquilo es justo donde una pantalla apagada no se descubre:
    // no tiene cola que mirar, así que nadie abre su tarjeta hasta que entra
    // la primera comanda de la noche y ya no hay tablet que la reciba.
    $tablet = tabletDelPanel((string) $this->pizza->kds_code, $this->pinPizza, 'Tablet horno');

    bateriaEnLaFila($tablet, 6, false);

    $comercio = comercioConTabletas(
        $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json(),
        'Pizza del Malecón',
    );

    // Ni una comanda abierta, y aun así la tablet está ahí y avisa.
    expect($comercio['open'])->toBe(0)
        ->and($comercio['units'])->toBe([])
        ->and($comercio['tablets'])->toHaveCount(1)
        ->and($comercio['tablets'][0]['low'])->toBeTrue();
});

it('never paints a tablet that has never reported as an empty one', function (): void {
    // La tablet recién colgada, o la abierta en un navegador que no sabe leer
    // su batería. Un cero de relleno la pintaría en rojo y mandaría a alguien
    // a enchufar una pantalla que está al 100 %.
    $tablet = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet muda');

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();
    $comercio = comercioConTabletas($cuerpo, 'Tacos del Puerto');
    $muda = tabletaDelFeed($comercio, 'Tablet muda');

    expect($muda['percent'])->toBeNull()
        ->and($muda['charging'])->toBeNull()
        ->and($muda['measured_at'])->toBeNull()
        ->and($muda['low'])->toBeFalse()
        // Y tampoco se sabe cuándo la vimos por última vez: recién colgada, ni
        // ha sondeado. Es el hueco que hace que el panel la ponga en gris en
        // vez de en rojo, en las dos cuentas.
        ->and($muda['seen_at'])->toBeNull();

    expect($tablet->battery_percent)->toBeNull();
});

it('counts only the tablets that actually need someone to bring a cable', function (): void {
    $pelada = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet pelada');
    $conCable = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet con cable');
    $llena = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet llena');
    $retirada = tabletDelPanel((string) $this->pizza->kds_code, $this->pinPizza, 'Tablet retirada');

    bateriaEnLaFila($pelada, 9, false);
    // Al 4 % pero enchufada: no hay a quién mandar, el problema se está
    // resolviendo solo. Contarla enseñaría a ignorar el aviso.
    bateriaEnLaFila($conCable, 4, true);
    bateriaEnLaFila($llena, 64, false);
    // Revocada y al 3 %: ya no entra, y su último nivel solo serviría para
    // mandar a alguien a buscar una pantalla que se llevaron ayer.
    bateriaEnLaFila($retirada, 3, false);
    app(TenantContext::class)->runAs($this->organizer, fn () => app(RevokeKdsDevice::class)(
        KdsDevice::query()->withoutGlobalScopes()->findOrFail($retirada->id),
    ));

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();

    $tacos = comercioConTabletas($cuerpo, 'Tacos del Puerto');

    expect(array_column($tacos['tablets'], 'low', 'name'))->toBe([
        'Tablet con cable' => false,
        'Tablet llena' => false,
        'Tablet pelada' => true,
    ]);

    // La revocada no aparece ni apagada ni encendida: se fue.
    expect(array_column(comercioConTabletas($cuerpo, 'Pizza del Malecón')['tablets'], 'name'))->toBe([]);
});

it('keeps answering 304 while nothing moves and repaints as soon as a battery drops', function (): void {
    $tablet = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet ventanilla');

    bateriaEnLaFila($tablet, 55, false);

    $primera = $this->actingAs($this->owner)->getJson($this->feed)->assertOk();
    $etag = (string) $primera->headers->get('ETag');

    expect($etag)->toStartWith('W/"');

    // Nada se ha movido: ni comandas ni batería. Sin este 304, cada pestaña
    // abierta en la oficina se bajaría el evento entero cada cinco segundos
    // toda la noche, y meter la batería en el cuerpo sería un mal negocio.
    $this->actingAs($this->owner)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson($this->feed)
        ->assertStatus(304);

    // Y la batería SÍ entra en el hash, que es la otra mitad del trato: si no
    // entrara, el aviso se quedaría congelado hasta que se moviera una
    // comanda, y en el puesto tranquilo eso es no avisar nunca.
    bateriaEnLaFila($tablet, 8, false);

    $segunda = $this->actingAs($this->owner)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson($this->feed)
        ->assertOk();

    expect($segunda->headers->get('ETag'))->not->toBe($etag)
        ->and(tabletaDelFeed(
            comercioConTabletas($segunda->json(), 'Tacos del Puerto'),
            'Tablet ventanilla',
        )['low'])->toBeTrue();
});

it('never carries a count that would freeze who needs a cable', function (): void {
    // La cifra de la tira se cuenta en el navegador, con los chips delante y el
    // reloj en la mano. Un total aquí volvería a ser lo de antes: un número
    // calculado cuando se sirvió la respuesta, que sigue diciendo «uno» sobre
    // una tablet de la que ya no se sabe nada. Si alguien lo vuelve a añadir,
    // que sea leyendo esta prueba y el docblock del controlador.
    $tablet = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet ventanilla');
    bateriaEnLaFila($tablet, 5, false);

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();

    expect($cuerpo['totals'])->not->toHaveKey('low_battery')
        ->and(comercioConTabletas($cuerpo, 'Tacos del Puerto'))->not->toHaveKey('low_battery');
});

it('stops asking for a cable for a tablet nobody has heard from', function (): void {
    // El caso que mandaba a alguien con un cable a un puesto vacío: la tablet
    // dijo 7 % a las once y se apagó. A las dos de la mañana su 7 % sigue en la
    // fila, y sin la marca del último latido la pantalla lo lee como una
    // emergencia que se arregla con un cable, cuando lo que hay que hacer es ir
    // a ver por qué esa pantalla no contesta.
    $apagada = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet apagada');
    $viva = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet viva');

    bateriaEnLaFila($apagada, 7, false, haceSegundos: 10800, vistoHaceSegundos: 10800);
    bateriaEnLaFila($viva, 7, false, haceSegundos: 900, vistoHaceSegundos: 20);

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();
    $comercio = comercioConTabletas($cuerpo, 'Tacos del Puerto');

    $callada = tabletaDelFeed($comercio, 'Tablet apagada');
    $contestando = tabletaDelFeed($comercio, 'Tablet viva');

    // Las dos llegan con el mismo 7 % y el mismo `low`: eso es un hecho de la
    // última lectura y no cambia porque pase el tiempo.
    expect($callada['low'])->toBeTrue()
        ->and($contestando['low'])->toBeTrue();

    // Lo que las separa es `seen_at`, que es lo único con lo que se puede
    // distinguir «hay que llevarle un cable» de «no sabemos de ella». Viaja la
    // MARCA y el umbral; la resta la hace quien tiene el reloj.
    expect(Carbon::parse($callada['seen_at'])->diffInSeconds(absolute: true))
        ->toBeGreaterThan($cuerpo['battery']['stale_seconds'])
        ->and(Carbon::parse($contestando['seen_at'])->diffInSeconds(absolute: true))
        ->toBeLessThan($cuerpo['battery']['stale_seconds']);

    // Y una batería quieta no es una tablet muerta: la viva lleva quince
    // minutos sin remedir —el mismo 7 % no se vuelve a guardar— y aun así
    // contesta. Mirar `measured_at` en vez de `seen_at` la daría por perdida.
    expect(Carbon::parse($contestando['measured_at'])->diffInSeconds(absolute: true))
        ->toBeGreaterThan($cuerpo['battery']['stale_seconds']);

    // La pantalla dice las dos cosas, con dos nombres y dos recados.
    $this->actingAs($this->owner)
        ->get($this->pantalla)
        ->assertOk()
        ->assertSee('Tabletas sin batería')
        ->assertSee('Tabletas sin noticias')
        ->assertSee('deja de pedir un cable', false);
});

it('never lets a tablet of another account into the strip', function (): void {
    $mia = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet ventanilla');
    bateriaEnLaFila($mia, 70, false);

    // Otra productora entera, con su propio festival en marcha y su tablet
    // agonizando a la misma hora. Su 2 % no puede sumar en mi tira de
    // indicadores ni asomar su nombre en mi tablero.
    $ajena = app(CreateTenant::class)('Otra Productora', null, TenantType::Organizer);

    [$comercioAjeno, $pinAjeno] = app(TenantContext::class)->runAs($ajena, function (): array {
        $evento = app(CreateEvent::class)('Festival Ajeno', now()->subDay(), now()->addDay());
        $comercio = vendorIn($evento, 'Arepas Ajenas');
        $puesto = outletFor($evento, 'Puesto Ajeno', OperatingUnitKind::Mixed, $comercio);

        return [$comercio, app(RotateOutletKdsPin::class)($puesto)];
    });

    bateriaEnLaFila(
        tabletDelPanel((string) $comercioAjeno->kds_code, $pinAjeno, 'Tablet Ajena'),
        2,
        false,
    );

    $cuerpo = $this->actingAs($this->owner)->getJson($this->feed)->assertOk()->json();

    // Ni el nombre, ni la fila, ni el 2 %: la tira de la otra cuenta no puede
    // contar nada mío porque aquí no hay nada suyo que contar.
    expect($cuerpo['vendors'])->toHaveCount(2)
        ->and(array_column(comercioConTabletas($cuerpo, 'Tacos del Puerto')['tablets'], 'name'))
        ->toBe(['Tablet ventanilla']);

    $this->actingAs($this->owner)
        ->get($this->pantalla)
        ->assertOk()
        ->assertDontSee('Tablet Ajena')
        ->assertSee('Tabletas sin batería')
        ->assertSee('salvo que estén cargando', false);
});

it('shows the battery of each tablet in the enrolled tablets table', function (): void {
    $pelada = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet pelada');
    $muda = tabletDelPanel((string) $this->tacos->kds_code, $this->pinTacos, 'Tablet muda');

    bateriaEnLaFila($pelada, 11, false);

    // La pantalla del montaje, no la de la noche: aquí no hace falta que sea
    // en vivo, pero la pregunta «¿cuál dejo cargando antes de abrir?» se
    // contesta aquí o no se contesta.
    $this->actingAs($this->owner)
        ->get('/event-panel/comercios/'.$this->tacos->id)
        ->assertOk()
        ->assertSee('Tabletas enroladas')
        ->assertSee('Tablet pelada')
        ->assertSee('11 %')
        // El hueco se dice con palabras: «Sin dato» no manda a nadie a ninguna
        // parte, y un 0 % de relleno sí lo haría.
        ->assertSee('Tablet muda')
        ->assertSee('Sin dato');

    expect($muda->battery_percent)->toBeNull();
});
