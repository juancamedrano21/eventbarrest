<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateEvent;
use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Kitchen\Actions\EnrollKdsDevice;
use App\Domains\Kitchen\Actions\RotateOutletKdsPin;
use App\Domains\Kitchen\EnrolledDevice;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;

/**
 * La pantalla desde la que el organizador gobierna las tabletas: ve el
 * código del comercio, rota el PIN de cada puesto y apaga pantallas.
 *
 * Lo que se fija aquí es que cada botón exija SU permiso —son tres
 * distintos y ninguno cubre al otro—, que el PIN en claro se vea una vez y
 * no vuelva, y que el puesto del comercio vecino sencillamente no exista
 * para estas URL.
 */
beforeEach(function (): void {
    $this->organizador = app(CreateTenant::class)('Bocao Food Fest', null, TenantType::Organizer);

    app(TenantContext::class)->runAs($this->organizador, function (): void {
        $this->evento = app(CreateEvent::class)('Bocao 2026', now()->addWeek(), now()->addWeeks(2));

        $this->comercio = app(CreateVendor::class)('Tacos del Puerto');
        app(InviteVendorToEvent::class)($this->evento, $this->comercio);

        $this->puesto = app(CreateEventOutlet::class)(
            $this->evento, $this->comercio, 'Puesto Norte', OperatingUnitKind::Kitchen,
        );
    });

    $this->duena = app(CreateTenantUser::class)(
        $this->organizador, 'Ana', 'ana@bocao.test', 'Secreta-2026', Role::Owner,
    );
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    app(VendorContext::class)->clear();
});

/**
 * Lo que la pantalla acabó dejando en la base, ya fuera de la petición.
 * Las funciones de un archivo de test son globales para toda la suite, así
 * que estos nombres son propios de esta pantalla y de nadie más.
 */
function releerElPuestoDelPanel(Tenant $cuenta, int $id): EventOutlet
{
    return app(TenantContext::class)->runAs(
        $cuenta,
        fn (): EventOutlet => EventOutlet::query()->findOrFail($id),
    );
}

function releerLaTabletaDelPanel(Tenant $cuenta, int $id): KdsDevice
{
    return app(TenantContext::class)->runAs(
        $cuenta,
        fn (): KdsDevice => KdsDevice::query()->findOrFail($id),
    );
}

/** Un usuario de la CUENTA (nunca de un comercio) con el rol que se pida. */
function alguienDeLaCuentaDelPanel(Tenant $cuenta, Role $rol, string $correo): User
{
    return app(CreateTenantUser::class)($cuenta, 'Quien sea', $correo, 'Secreta-2026', $rol);
}

/** Cuelga una tablet de verdad, por la misma puerta que la de la ventanilla. */
function colgarUnaTabletaDelPanel(Tenant $cuenta, Vendor $comercio, EventOutlet $puesto, string $nombre): EnrolledDevice
{
    $pin = app(TenantContext::class)->runAs($cuenta, fn (): string => app(RotateOutletKdsPin::class)($puesto));

    $codigo = (string) Vendor::query()->withoutTenancy()->findOrFail($comercio->id)->getAttribute('kds_code');

    return app(EnrollKdsDevice::class)($codigo, $pin, $nombre, null);
}

it('rotates the outlet pin and shows it exactly once', function (): void {
    // Ya circulaba un PIN: rotar tiene que dejar OTRO, no repetir el primero.
    $viejo = app(TenantContext::class)->runAs(
        $this->organizador,
        fn (): string => app(RotateOutletKdsPin::class)($this->puesto),
    );

    $huellaVieja = releerElPuestoDelPanel($this->organizador, $this->puesto->id)->getAttribute('kds_pin_hash');

    $respuesta = $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/puestos/{$this->puesto->id}/pin-kds")
        ->assertRedirect();

    $nuevo = $respuesta->getSession()->get('kdsPins')[$this->puesto->id];

    expect($nuevo)->toMatch('/^[0-9]{6}$/')
        ->and($nuevo)->not->toBe($viejo)
        ->and(releerElPuestoDelPanel($this->organizador, $this->puesto->id)->getAttribute('kds_pin_hash'))
        ->not->toBe($huellaVieja);

    // Se ve en la vuelta de la redirección y en ningún sitio más: en la base
    // solo queda su huella, así que la segunda visita no podría enseñarlo
    // aunque quisiera.
    $this->actingAs($this->duena)
        ->get("/event-panel/comercios/{$this->comercio->id}")
        ->assertOk()
        ->assertSee($nuevo)
        ->assertSee('no se vuelven a mostrar');

    $this->actingAs($this->duena)
        ->get("/event-panel/comercios/{$this->comercio->id}")
        ->assertOk()
        ->assertDontSee($nuevo)
        ->assertDontSee('no se vuelven a mostrar');
});

it('refuses to rotate the pin without the outlets permission', function (): void {
    // Almacén es de la cuenta, así que pasa la frontera de audiencia: lo
    // único que le falta es el permiso de puestos.
    $almacen = alguienDeLaCuentaDelPanel($this->organizador, Role::Warehouse, 'deposito@bocao.test');

    $this->actingAs($almacen)
        ->post("/event-panel/comercios/{$this->comercio->id}/puestos/{$this->puesto->id}/pin-kds")
        ->assertForbidden();

    expect(releerElPuestoDelPanel($this->organizador, $this->puesto->id)->getAttribute('kds_pin_hash'))->toBeNull();
});

it('unlocks the outlet without changing its pin', function (): void {
    $pin = app(TenantContext::class)->runAs($this->organizador, function (): string {
        $pin = app(RotateOutletKdsPin::class)($this->puesto);

        // Como si diez intentos a ciegas hubieran dejado el puesto en
        // penitencia justo el día del montaje.
        $this->puesto->setAttribute('kds_pin_locked_until', now()->addMinutes(15));
        $this->puesto->save();

        return $pin;
    });

    $huella = releerElPuestoDelPanel($this->organizador, $this->puesto->id)->getAttribute('kds_pin_hash');

    $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/puestos/{$this->puesto->id}/pin-kds/desbloquear")
        ->assertRedirect();

    $puesto = releerElPuestoDelPanel($this->organizador, $this->puesto->id);

    expect($puesto->getAttribute('kds_pin_locked_until'))->toBeNull()
        ->and($puesto->getAttribute('kds_pin_hash'))->toBe($huella);

    // El que lleva el PIN escrito sigue entrando: desbloquear no reparte
    // nada nuevo por el recinto.
    $codigo = (string) Vendor::query()->withoutTenancy()->findOrFail($this->comercio->id)->getAttribute('kds_code');

    expect(app(EnrollKdsDevice::class)($codigo, $pin, 'Tablet ventanilla', null)->device->exists)->toBeTrue();
});

it('revokes a single tablet and leaves it out', function (): void {
    $enrolada = colgarUnaTabletaDelPanel($this->organizador, $this->comercio, $this->puesto, 'Tablet ventanilla');

    $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/tabletas/{$enrolada->device->id}/revocar")
        ->assertRedirect();

    expect(releerLaTabletaDelPanel($this->organizador, $enrolada->device->id)->estaRevocada())->toBeTrue();

    // No se borra: el rastro de qué pantalla movió qué comanda tiene que
    // seguir teniendo a quién apuntar.
    $this->actingAs($this->duena)
        ->get("/event-panel/comercios/{$this->comercio->id}")
        ->assertOk()
        ->assertSee('Tablet ventanilla')
        ->assertSee('Revocada el');
});

it('refuses to revoke a tablet without the devices permission', function (): void {
    $enrolada = colgarUnaTabletaDelPanel($this->organizador, $this->comercio, $this->puesto, 'Tablet ventanilla');

    // Gerente de eventos SÍ administra puestos, así que si esta ruta se
    // colgara del permiso de puestos por descuido, este test pasaría igual.
    // Lo que prueba es que revocar exige el permiso de dispositivos.
    $gerente = alguienDeLaCuentaDelPanel($this->organizador, Role::EventManager, 'gerente@bocao.test');

    $this->actingAs($gerente)
        ->post("/event-panel/comercios/{$this->comercio->id}/tabletas/{$enrolada->device->id}/revocar")
        ->assertForbidden();

    $this->actingAs($gerente)
        ->post("/event-panel/comercios/{$this->comercio->id}/tabletas/revocar-todas")
        ->assertForbidden();

    expect(releerLaTabletaDelPanel($this->organizador, $enrolada->device->id)->estaRevocada())->toBeFalse();
});

it('never touches an outlet of another vendor', function (): void {
    $ajeno = app(TenantContext::class)->runAs($this->organizador, function (): EventOutlet {
        $otro = app(CreateVendor::class)('Pizza del Sur');
        app(InviteVendorToEvent::class)($this->evento, $otro);

        return app(CreateEventOutlet::class)($this->evento, $otro, 'Puesto Sur', OperatingUnitKind::Bar);
    });

    // Mismo evento y misma cuenta: lo único que falla es que el puesto no es
    // de ese comercio. Y contesta 404, no 403: lo que no es tuyo no existe,
    // así probar ids a mano no dibuja el mapa del vecino.
    $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/puestos/{$ajeno->id}/pin-kds")
        ->assertNotFound();

    $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/puestos/{$ajeno->id}/pin-kds/desbloquear")
        ->assertNotFound();

    expect(releerElPuestoDelPanel($this->organizador, $ajeno->id)->getAttribute('kds_pin_hash'))->toBeNull();
});

it('never revokes a tablet of another vendor', function (): void {
    $ajena = app(TenantContext::class)->runAs($this->organizador, function (): EnrolledDevice {
        $otro = app(CreateVendor::class)('Pizza del Sur');
        app(InviteVendorToEvent::class)($this->evento, $otro);
        $puesto = app(CreateEventOutlet::class)($this->evento, $otro, 'Puesto Sur', OperatingUnitKind::Bar);

        return colgarUnaTabletaDelPanel($this->organizador, $otro, $puesto, 'Tablet del vecino');
    });

    $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/tabletas/{$ajena->device->id}/revocar")
        ->assertNotFound();

    expect(releerLaTabletaDelPanel($this->organizador, $ajena->device->id)->estaRevocada())->toBeFalse();
});

it('kills every tablet and rotates every pin in one blow', function (): void {
    $ventanilla = colgarUnaTabletaDelPanel($this->organizador, $this->comercio, $this->puesto, 'Tablet ventanilla');
    $trastienda = colgarUnaTabletaDelPanel($this->organizador, $this->comercio, $this->puesto, 'Tablet de atras');

    $huellaVieja = releerElPuestoDelPanel($this->organizador, $this->puesto->id)->getAttribute('kds_pin_hash');

    $respuesta = $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/tabletas/revocar-todas")
        ->assertRedirect();

    expect(releerLaTabletaDelPanel($this->organizador, $ventanilla->device->id)->estaRevocada())->toBeTrue()
        ->and(releerLaTabletaDelPanel($this->organizador, $trastienda->device->id)->estaRevocada())->toBeTrue()
        // Revocar sin rotar dejaría que quien se llevó la tablet la colgara
        // otra vez con el PIN que ya vio: por eso el martillo hace las dos.
        ->and(releerElPuestoDelPanel($this->organizador, $this->puesto->id)->getAttribute('kds_pin_hash'))
        ->not->toBe($huellaVieja)
        ->and($respuesta->getSession()->get('kdsPins'))->toHaveKey($this->puesto->id);
});

it('regenerates the vendor code and keeps the old one from enrolling', function (): void {
    $pin = app(TenantContext::class)->runAs(
        $this->organizador,
        fn (): string => app(RotateOutletKdsPin::class)($this->puesto),
    );

    $viejo = (string) Vendor::query()->withoutTenancy()->findOrFail($this->comercio->id)->getAttribute('kds_code');

    $this->actingAs($this->duena)
        ->post("/event-panel/comercios/{$this->comercio->id}/codigo-kds")
        ->assertRedirect();

    $nuevo = (string) Vendor::query()->withoutTenancy()->findOrFail($this->comercio->id)->getAttribute('kds_code');

    expect($nuevo)->not->toBe($viejo)->and($nuevo)->toHaveLength(8);

    // El papel viejo pegado en el puesto ya no sirve para colgar nada.
    expect(fn () => app(EnrollKdsDevice::class)($viejo, $pin, 'Tablet tardía', null))
        ->toThrow(KitchenException::class);

    $this->actingAs($this->duena)
        ->get("/event-panel/comercios/{$this->comercio->id}")
        ->assertOk()
        ->assertSee($nuevo);
});

it('refuses to regenerate the code without the vendors permission', function (): void {
    $gerente = alguienDeLaCuentaDelPanel($this->organizador, Role::EventManager, 'gerente@bocao.test');

    $viejo = (string) Vendor::query()->withoutTenancy()->findOrFail($this->comercio->id)->getAttribute('kds_code');

    $this->actingAs($gerente)
        ->post("/event-panel/comercios/{$this->comercio->id}/codigo-kds")
        ->assertForbidden();

    expect((string) Vendor::query()->withoutTenancy()->findOrFail($this->comercio->id)->getAttribute('kds_code'))
        ->toBe($viejo);
});
