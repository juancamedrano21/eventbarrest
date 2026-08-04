<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RevokeKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\Actions\UnlockOutletKdsPin;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * La identidad de la tablet: el código público del comercio y el PIN
 * secreto del puesto. Es la única puerta de la plataforma que se abre sin
 * cuenta activa, así que lo que se fija aquí es qué deja entrar, qué no y —
 * sobre todo— que lo que no entra nunca cuente POR QUÉ no entró.
 */
beforeEach(function (): void {
    $montaje = montarUnComercio('Bocao Food Fest', 'Tacos del Puerto', 'Puesto Norte');

    $this->organizer = $montaje['tenant'];
    $this->evento = $montaje['evento'];
    $this->vendor = $montaje['vendor'];
    $this->puesto = $montaje['puesto'];
    $this->pin = $montaje['pin'];
});

/**
 * Un organizador con su evento en pie, un comercio dentro y un puesto con
 * PIN puesto. Se usa dos veces —la segunda para probar que el código se
 * resuelve entre cuentas— así que vive suelto y no dentro del beforeEach.
 *
 * @return array{tenant: mixed, evento: mixed, vendor: mixed, puesto: mixed, pin: string}
 */
function montarUnComercio(string $cuenta, string $comercio, string $puesto): array
{
    $tenant = app(CreateTenant::class)($cuenta, null, TenantType::Organizer);

    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $comercio, $puesto): array {
        $evento = app(CreateEvent::class)(
            'Festival de '.$comercio, now()->addDay(), now()->addDays(3), null, EventStatus::Active,
        );

        $vendor = app(CreateVendor::class)($comercio);
        app(InviteVendorToEvent::class)($evento, $vendor);

        $unidad = app(CreateEventOutlet::class)($evento, $vendor, $puesto, OperatingUnitKind::Kitchen);

        return [
            'tenant' => $tenant,
            'evento' => $evento,
            'vendor' => $vendor,
            'puesto' => $unidad,
            'pin' => app(RotateOutletKdsPin::class)($unidad),
        ];
    });
}

/**
 * El comercio leído de la base sin contexto de cuenta, que es donde vive la
 * racha de intentos a ciegas: un intento fallido no identifica ningún puesto
 * —el índice ciego se deriva del propio PIN— y sí identifica el comercio.
 */
function comercioDelKds(int $id): Vendor
{
    /** @var Vendor $comercio */
    $comercio = Vendor::query()->withoutTenancy()->findOrFail($id);

    return $comercio;
}

/** Ejecuta lo que debe fallar y devuelve el fallo, para poder mirarlo por dentro. */
function rechazoDelKds(Closure $accion): KitchenException
{
    try {
        $accion();
    } catch (KitchenException $e) {
        return $e;
    }

    throw new RuntimeException('Se esperaba un KitchenException y la llamada pasó sin rechistar.');
}

it('gives every new vendor a dictable code', function (): void {
    // Sin O/0 ni I/1/l: el código se canta por teléfono en pleno montaje.
    expect($this->vendor->kds_code)->toHaveLength(8)
        ->toMatch('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/');
});

it('enrols a tablet with the vendor code and the outlet pin', function (): void {
    // Tal como llega de una tablet: en minúscula y con el guion con el que
    // se imprimió en la hoja del puesto.
    $codigo = (string) $this->vendor->kds_code;
    $tecleado = mb_strtolower(mb_substr($codigo, 0, 4).'-'.mb_substr($codigo, 4));

    $enrolada = app(EnrollKdsDevice::class)($tecleado, $this->pin, 'Tablet ventanilla', null);

    expect($enrolada->plainToken)->toHaveLength(64);
    expect($enrolada->device->operating_unit_id)->toBe($this->puesto->id);
    expect($enrolada->device->vendor_id)->toBe($this->vendor->id);
    expect($enrolada->device->tenant_id)->toBe($this->organizer->id);
    expect($enrolada->device->name)->toBe('Tablet ventanilla');

    // En la base solo queda el sha256: el claro sale una vez y no vuelve.
    expect($enrolada->device->token_hash)->toBe(hash('sha256', $enrolada->plainToken));

    // El gancho de la cocina compartida: hoy, su puesto y nada más.
    expect($enrolada->device->unidadesVigiladas())->toBe([$this->puesto->id]);
});

it('answers the same way to an unknown code and to a wrong pin', function (): void {
    $codigoInventado = rechazoDelKds(fn () => app(EnrollKdsDevice::class)('ZZZZZZZZ', $this->pin, 'Tablet', null));
    $pinErrado = rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
        (string) $this->vendor->kds_code, '000000', 'Tablet', null,
    ));

    // Idénticos a propósito: distinguirlos convertiría el código público en
    // una lista de los comercios de la plataforma.
    expect($codigoInventado->getMessage())->toBe($pinErrado->getMessage());
    expect($codigoInventado->errorCode)->toBe($pinErrado->errorCode)->toBe('kds_enrollment_rejected');

    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(0);
});

it('refuses a closed outlet even with the right pin', function (): void {
    // Es exactamente lo que deja RemoveVendorFromEvent al sacar a un
    // comercio del evento: el puesto cerrado, con su PIN intacto.
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        $this->puesto->update(['status' => OperatingUnitStatus::Closed]);
    });

    $error = rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
        (string) $this->vendor->kds_code, $this->pin, 'Tablet', null,
    ));

    expect($error->errorCode)->toBe('kds_enrollment_rejected');
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(0);

    // Y el PIN bueno no le gasta intentos a nadie: quien acierta no es
    // quien está probando a ciegas, aunque su puesto esté cerrado.
    expect((int) comercioDelKds((int) $this->vendor->id)->getAttribute('kds_blind_attempts'))->toBe(0);
});

it('refuses a tablet once the event is over', function (): void {
    app(TenantContext::class)->runAs($this->organizer, function (): void {
        Event::query()->whereKey($this->evento->id)->update(['status' => EventStatus::Closed->value]);
    });

    $error = rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
        (string) $this->vendor->kds_code, $this->pin, 'Tablet', null,
    ));

    expect($error->errorCode)->toBe('kds_enrollment_rejected');
});

it('counts the tenth blind failure without ever shutting out the right pin', function (): void {
    // Este test decía antes lo contrario: que al décimo fallo «con el puesto en
    // penitencia ni siquiera el PIN bueno entra». Eso ERA la denegación de
    // servicio del encargo — el código del comercio está impreso y pegado en el
    // puesto, así que cualquiera quemaba diez intentos y dejaba a la cocina sin
    // poder colgar tabletas con su PIN CORRECTO—. La penitencia se queda, porque
    // un freno que no se puede alcanzar es código muerto, pero su consecuencia
    // ya no es cerrar la puerta: es dejar de gastar bcrypt en contestar que no.
    for ($intento = 1; $intento <= 10; $intento++) {
        rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
            (string) $this->vendor->kds_code, '000000', 'Tablet', null,
        ));
    }

    // La racha es del COMERCIO —es lo único que un intento fallido identifica—
    // y no de sus puestos: replicarla en ellos era lo que encendía un cartel de
    // «Bloqueado» en las treinta barras a la vez sin que ninguna lo estuviera.
    expect(comercioDelKds((int) $this->vendor->id)->getAttribute('kds_blind_pause_until'))->not->toBeNull();

    // Y con la penitencia encendida, el PIN bueno entra a la primera.
    $enrolada = app(EnrollKdsDevice::class)((string) $this->vendor->kds_code, $this->pin, 'Tablet', null);

    expect($enrolada->device->operating_unit_id)->toBe($this->puesto->id);

    // Entrar con el PIN bueno rompe la racha: lo que el freno persigue son las
    // tandas de PIN inventados, no el dedo torpe de quien acabó entrando.
    $comercio = comercioDelKds((int) $this->vendor->id);

    expect((int) $comercio->getAttribute('kds_blind_attempts'))->toBe(0);
    expect($comercio->getAttribute('kds_blind_pause_until'))->toBeNull();
});

it('clears the whole vendor streak, not one outlet of thirty', function (): void {
    // Esta acción ya no rescata a ninguna cocina —la racha no cierra ninguna
    // puerta— y por eso el panel no ofrece su botón. Lo que sí tiene que hacer,
    // mientras su ruta siga registrada, es apagar lo que dice apagar: la cuenta
    // ENTERA del comercio. Mientras la racha se escribía replicada en los
    // puestos, esto limpiaba UNO y los otros veintinueve seguían encendidos.
    for ($intento = 1; $intento <= 10; $intento++) {
        rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
            (string) $this->vendor->kds_code, '000000', 'Tablet', null,
        ));
    }

    expect(comercioDelKds((int) $this->vendor->id)->getAttribute('kds_blind_pause_until'))->not->toBeNull();

    app(UnlockOutletKdsPin::class)($this->puesto);

    $comercio = comercioDelKds((int) $this->vendor->id);

    expect((int) $comercio->getAttribute('kds_blind_attempts'))->toBe(0);
    expect($comercio->getAttribute('kds_blind_pause_until'))->toBeNull();

    // Y el PIN sigue siendo el mismo: no hay que repartir nada por el recinto.
    expect(app(EnrollKdsDevice::class)((string) $this->vendor->kds_code, $this->pin, 'Tablet', null)->device->exists)
        ->toBeTrue();
});

it('keeps already enrolled tablets alive when the pin is rotated', function (): void {
    $enrolada = app(EnrollKdsDevice::class)((string) $this->vendor->kds_code, $this->pin, 'Tablet', null);

    $nuevoPin = app(RotateOutletKdsPin::class)($this->puesto);

    expect($nuevoPin)->not->toBe($this->pin)->toMatch('/^\d{6}$/');

    // La tablet colgada sigue viva: su token no depende del PIN, que solo
    // sirve para dejar entrar a la SIGUIENTE. Si no fuese así, rotar el PIN
    // a mitad del festival apagaría todas las pantallas del puesto a la vez.
    $enrolada->device->refresh();
    expect($enrolada->device->revoked_at)->toBeNull();
    expect($enrolada->device->token_hash)->toBe(hash('sha256', $enrolada->plainToken));

    // Y el PIN viejo ya no abre.
    rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
        (string) $this->vendor->kds_code, $this->pin, 'Otra tablet', null,
    ));
});

it('resolves the vendor code across tenants', function (): void {
    $otro = montarUnComercio('Sabores del Este', 'Pizzas Doña Ana', 'Puesto Sur');

    expect($otro['vendor']->kds_code)->not->toBe($this->vendor->kds_code);

    // Sin cuenta activa: la tablet teclea ocho caracteres y no sabe —ni
    // tiene por qué saber— de qué organizador es su comercio.
    expect(app(TenantContext::class)->check())->toBeFalse();

    $enrolada = app(EnrollKdsDevice::class)(
        (string) $otro['vendor']->kds_code, $otro['pin'], 'Tablet del este', null,
    );

    expect($enrolada->device->tenant_id)->toBe($otro['tenant']->id);
    expect($enrolada->device->operating_unit_id)->toBe($otro['puesto']->id);

    // Y el PIN del vecino no abre esta puerta, aunque sea correcto en su
    // propia cuenta: el código es lo que decide de quién es la tablet.
    rechazoDelKds(fn () => app(EnrollKdsDevice::class)(
        (string) $this->vendor->kds_code, $otro['pin'], 'Tablet', null,
    ));
});

it('revokes one tablet without touching its trail', function (): void {
    $enrolada = app(EnrollKdsDevice::class)((string) $this->vendor->kds_code, $this->pin, 'Tablet', null);

    app(RevokeKdsDevice::class)($enrolada->device);

    $cortada = $enrolada->device->revoked_at;
    expect($enrolada->device->estaRevocada())->toBeTrue();

    // Idempotente y sin reescribir cuándo dejó de entrar: la fila se queda
    // porque las comandas guardan qué dispositivo las tocó.
    app(RevokeKdsDevice::class)($enrolada->device);
    expect($enrolada->device->revoked_at)->toEqual($cortada);
    expect(KdsDevice::query()->withoutTenancy()->count())->toBe(1);
});
