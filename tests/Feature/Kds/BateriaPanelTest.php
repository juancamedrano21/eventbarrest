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

/**
 * La batería de las tabletas donde el organizador ya está mirando.
 *
 * Lo que se vigila aquí no es que un número llegue —de eso responde
 * BateriaTest, que cubre el camino desde el navegador de la tablet— sino las
 * cuatro maneras que tiene esta pantalla de mentir la noche del festival:
 * pintar como agotada una tablet que nunca dijo nada, mandar a alguien con un
 * cable a un puesto donde ya hay uno puesto, esconder la tablet moribunda del
 * puesto tranquilo porque ese puesto no tiene cola, y matar el 304 —que es lo
 * que hace que este sondeo de cinco segundos sea barato— al meter la batería
 * en el cuerpo.
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
 */
function bateriaEnLaFila(KdsDevice $device, ?int $nivel, ?bool $cargando = false, int $haceSegundos = 0): void
{
    KdsDevice::query()->withoutGlobalScopes()->whereKey($device->id)->update([
        'battery_percent' => $nivel,
        'battery_charging' => $cargando,
        'battery_at' => $nivel === null ? null : now()->subSeconds($haceSegundos),
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
        ->and($comercio['low_battery'])->toBe(1);
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
        ->and($comercio['low_battery'])->toBe(0)
        ->and($cuerpo['totals']['low_battery'])->toBe(0);

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

    expect($cuerpo['totals']['low_battery'])->toBe(1);

    $tacos = comercioConTabletas($cuerpo, 'Tacos del Puerto');

    expect($tacos['low_battery'])->toBe(1)
        ->and(array_column($tacos['tablets'], 'low', 'name'))->toBe([
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
        ->and($segunda->json('totals.low_battery'))->toBe(1);
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

    expect($cuerpo['totals']['low_battery'])->toBe(0);

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
        // El hueco se dice con palabras y no con un cero: «Sin dato» no manda
        // a nadie a ninguna parte, y un 0 % sí.
        ->assertSee('Sin dato')
        ->assertDontSee('0 %');

    expect($muda->battery_percent)->toBeNull();
});
