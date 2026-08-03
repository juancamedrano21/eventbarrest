<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * La batería de la tablet: el camino desde el navegador hasta la base.
 *
 * Lo que se prueba aquí no es que un número se guarde, sino las cuatro cosas
 * que solo se descubren en la cocina si se rompen: que un dato basura se
 * ignora en vez de aterrizar en la fila, que el freno de escrituras aguanta
 * el sondeo de tres segundos pero se aparta cuando alguien enchufa la tablet,
 * que mandar la batería NO mata el 304 —si lo matara, veinte tabletas se
 * bajarían el tablero entero cada tres segundos toda la noche— y que una
 * tablet no tiene por dónde escribir la batería de la de al lado.
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
        $this->pinNorte = app(RotateOutletKdsPin::class)($this->norte);
    });

    // DOS tabletas en el MISMO puesto del MISMO comercio: es el par que
    // ningún scope separa, y por tanto el único caso donde una podría acabar
    // escribiendo sobre la otra sin que nada roto lo delate.
    $this->tablet = app(EnrollKdsDevice::class)(
        (string) $this->tacos->kds_code, $this->pinNorte, 'Tablet ventanilla', null,
    );
    $this->vecina = app(EnrollKdsDevice::class)(
        (string) $this->tacos->kds_code, $this->pinNorte, 'Tablet de atrás', null,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/** El sondeo del tablero tal cual lo manda la tablet, con su batería dentro. */
function sondeoDeLaTablet(string $token, array $cabeceras = []): TestResponse
{
    return test()->withToken($token)->getJson('/api/kds/comandas', $cabeceras);
}

/**
 * La fila del dispositivo tal como quedó en la base. Sin ningún scope: lo que
 * se comprueba aquí es lo que hay escrito, no lo que la vista de turno deja
 * ver.
 */
function filaDeLaTablet(KdsDevice $device): KdsDevice
{
    return KdsDevice::query()->withoutGlobalScopes()->findOrFail($device->id);
}

it('stores the battery the tablet reported in its header', function (): void {
    sondeoDeLaTablet($this->tablet->plainToken, [
        'X-Kds-Bateria' => '87',
        'X-Kds-Cargando' => '1',
    ])->assertOk();

    $fila = filaDeLaTablet($this->tablet->device);

    expect($fila->battery_percent)->toBe(87)
        ->and($fila->battery_charging)->toBeTrue()
        ->and($fila->battery_at)->not->toBeNull()
        // Cargando no está en apuros aunque el nivel sea bajo: ya hay cable.
        ->and($fila->sabeSuBateria())->toBeTrue();
});

it('leaves the battery unknown when the tablet sends no headers at all', function (): void {
    // El navegador que no sabe leerse la batería —Safari, Firefox— no manda
    // nada, y el hueco tiene que seguir siendo un hueco. Un cero de relleno
    // pintaría en rojo una tablet perfectamente cargada.
    sondeoDeLaTablet($this->tablet->plainToken)->assertOk();

    $fila = filaDeLaTablet($this->tablet->device);

    expect($fila->battery_percent)->toBeNull()
        ->and($fila->battery_at)->toBeNull()
        ->and($fila->sabeSuBateria())->toBeFalse()
        ->and($fila->bateriaEnApuros())->toBeFalse()
        // Y aun así la tablet dio señales de vida: el latido es otra cosa.
        ->and($fila->last_seen_at)->not->toBeNull();
});

it('ignores an absurd battery header instead of storing it', function (string $absurdo): void {
    sondeoDeLaTablet($this->tablet->plainToken, ['X-Kds-Bateria' => $absurdo])->assertOk();

    $fila = filaDeLaTablet($this->tablet->device);

    // Ni se guarda ni se recorta al borde más cercano: un 150 no es un 100,
    // es una fuente que no sabe lo que dice. Y sobre todo NO se rechaza la
    // petición: al otro lado hay una pantalla de cocina, y quedarse sin
    // comandas por una cabecera rara sería cambiar un adorno del panel por
    // el servicio de la noche.
    expect($fila->battery_percent)->toBeNull()
        ->and($fila->battery_at)->toBeNull()
        ->and($fila->last_seen_at)->not->toBeNull();
})->with(['150', '101', '-3', 'hola', '87.5', '']);

it('does not write twice when two polls report the same level', function (): void {
    $token = $this->tablet->plainToken;

    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '64', 'X-Kds-Cargando' => '0'])->assertOk();

    $primera = filaDeLaTablet($this->tablet->device);

    // Tres segundos: lo que tarda la tablet en volver a preguntar. Veinte
    // tabletas a ese ritmo serían cuatrocientas escrituras por minuto para
    // no contar nada nuevo, y es exactamente el freno que ya existía para
    // el latido.
    $this->travel(3)->seconds();

    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '64', 'X-Kds-Cargando' => '0'])->assertOk();

    // Un punto de bajada tampoco escribe: es ruido de medición, no noticia.
    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '63', 'X-Kds-Cargando' => '0'])->assertOk();

    $segunda = filaDeLaTablet($this->tablet->device);

    expect($segunda->updated_at?->equalTo($primera->updated_at))->toBeTrue()
        ->and($segunda->battery_at?->equalTo($primera->battery_at))->toBeTrue()
        // Y lo guardado sigue siendo lo de la primera vez, no lo del último
        // sondeo: el panel se entera del 63 en el latido del minuto.
        ->and($segunda->battery_percent)->toBe(64);
});

it('writes at once when someone plugs the tablet in', function (): void {
    $token = $this->tablet->plainToken;

    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '30', 'X-Kds-Cargando' => '0'])->assertOk();

    $antes = filaDeLaTablet($this->tablet->device);

    $this->travel(3)->seconds();

    // Enchufar es un HECHO, no una medida: quien acaba de poner el cable
    // quiere verlo en el panel ya, no dentro de un minuto.
    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '30', 'X-Kds-Cargando' => '1'])->assertOk();

    $despues = filaDeLaTablet($this->tablet->device);

    expect($despues->battery_charging)->toBeTrue()
        ->and($despues->battery_at?->greaterThan($antes->battery_at))->toBeTrue()
        // Al 30 % y cargando ya no hay a quien mandar con un cable.
        ->and($despues->bateriaEnApuros())->toBeFalse()
        ->and($antes->bateriaEnApuros())->toBeFalse();
});

it('writes at once when the level falls hard between two polls', function (): void {
    $token = $this->tablet->plainToken;

    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '25', 'X-Kds-Cargando' => '0'])->assertOk();

    $this->travel(3)->seconds();

    // Una caída a plomo es justo el aviso que llega tarde si se espera al
    // minuto: para entonces la pantalla puede estar ya apagada.
    sondeoDeLaTablet($token, ['X-Kds-Bateria' => '18', 'X-Kds-Cargando' => '0'])->assertOk();

    $fila = filaDeLaTablet($this->tablet->device);

    expect($fila->battery_percent)->toBe(18)
        ->and($fila->bateriaEnApuros())->toBeTrue()
        ->and($fila->antiguedadDeLaBateria())->toBe(0);
});

it('keeps answering 304 while the battery travels in the headers', function (): void {
    $token = $this->tablet->plainToken;

    $primero = sondeoDeLaTablet($token, ['X-Kds-Bateria' => '90', 'X-Kds-Cargando' => '0'])->assertOk();
    $etag = (string) $primero->headers->get('ETag');

    expect($etag)->toStartWith('W/"');

    $this->travel(3)->seconds();

    // La batería cambió MUCHO y el tablero no cambió nada: tiene que salir
    // un 304. Si la batería viajara en la URL este 304 no ocurriría jamás
    // —cada nivel sería otra URL— y cada tablet se bajaría el tablero entero
    // cada tres segundos durante toda la noche.
    sondeoDeLaTablet($token, [
        'If-None-Match' => $etag,
        'X-Kds-Bateria' => '41',
        'X-Kds-Cargando' => '1',
    ])->assertStatus(304);

    // Y aun sin cuerpo de respuesta, la batería SÍ se guardó: el middleware
    // corre antes de que el controlador decida que no hay nada que mandar.
    expect(filaDeLaTablet($this->tablet->device)->battery_percent)->toBe(41);
});

it('never lets a tablet write the battery of another', function (): void {
    // Misma cuenta, mismo comercio, mismo puesto: lo único que separa a
    // estas dos tabletas es el token que presenta cada una.
    sondeoDeLaTablet($this->tablet->plainToken, ['X-Kds-Bateria' => '90'])->assertOk();

    expect(filaDeLaTablet($this->tablet->device)->battery_percent)->toBe(90)
        ->and(filaDeLaTablet($this->vecina->device)->battery_percent)->toBeNull();

    // Y no hay por dónde apuntar a la otra ni queriendo: la cabecera lleva un
    // número y nada más, y el dispositivo sale SIEMPRE del token. Un id
    // colado en la petición no lo lee nadie.
    sondeoDeLaTablet($this->vecina->plainToken, [
        'X-Kds-Bateria' => '11',
        'X-Kds-Dispositivo' => (string) $this->tablet->device->id,
    ])->assertOk();

    expect(filaDeLaTablet($this->vecina->device)->battery_percent)->toBe(11)
        ->and(filaDeLaTablet($this->tablet->device)->battery_percent)->toBe(90);
});

it('does not wake the identity guard when writing the battery', function (): void {
    $antes = filaDeLaTablet($this->tablet->device);

    sondeoDeLaTablet($this->tablet->plainToken, [
        'X-Kds-Bateria' => '55',
        'X-Kds-Cargando' => '0',
    ])->assertOk();

    $despues = filaDeLaTablet($this->tablet->device);

    // El guard de updating lanza KitchenException en cuanto se ensucia una
    // de estas tres, y el latido se guarda con save() —no con
    // saveQuietly()— precisamente para que corra. El 200 de arriba ya dice
    // que no saltó; esto deja escrito por qué: la batería no es identidad.
    expect($despues->token_hash)->toBe($antes->token_hash)
        ->and($despues->operating_unit_id)->toBe($antes->operating_unit_id)
        ->and($despues->vendor_id)->toBe($antes->vendor_id)
        ->and($despues->battery_percent)->toBe(55);
});
