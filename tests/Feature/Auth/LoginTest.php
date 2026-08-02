<?php

declare(strict_types=1);

use App\Domains\EventManagement\Actions\CreateVendor;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La entrada única (ADR-007): una pantalla, y cada quien acaba en SU
 * puerta. El login es también la frontera de las suspensiones.
 */
beforeEach(function (): void {
    RateLimiter::clear('ana@x.test|127.0.0.1');

    $this->organizer = app(CreateTenant::class)('Bocao', null, TenantType::Organizer);
    $this->vendor = app(TenantContext::class)->runAs(
        $this->organizer,
        fn () => app(CreateVendor::class)('Tacos del Puerto'),
    );
});

afterEach(fn () => app(TenantContext::class)->clear());

it('sends each audience to its own door', function (): void {
    $owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
    $encargada = app(CreateTenantUser::class)(
        $this->organizer, 'Caro', 'caro@x.test', 'Secreta-2026', Role::VendorManager, $this->vendor,
    );
    $cajera = app(CreateTenantUser::class)(
        $this->organizer, 'Lia', 'lia@x.test', 'Secreta-2026', Role::Cashier, $this->vendor, username: 'lia',
    );

    // Equipo de la cuenta → el panel.
    $this->post('/entrar', ['usuario' => 'ana@x.test', 'password' => 'Secreta-2026'])
        ->assertRedirect('/event-panel');
    $this->assertAuthenticatedAs($owner);
    $this->post('/salir')->assertRedirect('/entrar');

    // Encargada del comercio → su casa.
    $this->post('/entrar', ['usuario' => 'caro@x.test', 'password' => 'Secreta-2026'])
        ->assertRedirect('/event-vendor');
    $this->post('/salir');

    // Cajera: su trabajo entero es la caja, y entra POR USUARIO, no correo.
    // La caja de SU mundo: /pos rechaza por modalidad al cajero de un
    // comercio de evento, así que mandarlo allí era un callejón sin salida.
    $this->post('/entrar', ['usuario' => 'lia', 'password' => 'Secreta-2026'])
        ->assertRedirect('/event-pos');
    $this->assertAuthenticatedAs($cajera);
});

it('never reveals whether an account exists', function (): void {
    app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    // EL MISMO mensaje para «no existe» y para «clave errada»: la pantalla
    // no le dice a nadie qué cuentas hay.
    $mismo = ['usuario' => 'Esas credenciales no coinciden con ninguna cuenta.'];

    $this->post('/entrar', ['usuario' => 'nadie@x.test', 'password' => 'loquesea'])
        ->assertSessionHasErrors($mismo);

    $this->post('/entrar', ['usuario' => 'ana@x.test', 'password' => 'incorrecta'])
        ->assertSessionHasErrors($mismo);

    $this->assertGuest();
});

it('throttles brute force by identity and origin', function (): void {
    app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    for ($intento = 0; $intento < 5; $intento++) {
        $this->post('/entrar', ['usuario' => 'ana@x.test', 'password' => 'mala']);
    }

    // Al sexto, ni con la contraseña correcta.
    $this->post('/entrar', ['usuario' => 'ana@x.test', 'password' => 'Secreta-2026'])
        ->assertSessionHasErrors('usuario');

    $this->assertGuest();
});

it('closes the door on a suspended account', function (): void {
    $owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
    $this->organizer->update(['status' => TenantStatus::Suspended]);

    $this->post('/entrar', ['usuario' => 'ana@x.test', 'password' => 'Secreta-2026'])
        ->assertSessionHasErrors('usuario');

    // Y no queda sesión a medias.
    $this->assertGuest();
    expect(User::query()->find($owner->id))->not->toBeNull();
});

it('takes a logged in user to their door instead of the form', function (): void {
    $owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);

    $this->actingAs($owner)->get('/entrar')->assertRedirect('/event-panel');
});

it('sends guests to the login screen', function (): void {
    $this->get('/event-panel')->assertRedirect('/entrar');
    $this->get('/event-vendor')->assertRedirect('/entrar');
});

it('turns the root into a signpost, not a welcome page', function (): void {
    $this->get('/')->assertRedirect('/entrar');

    $owner = app(CreateTenantUser::class)($this->organizer, 'Ana', 'ana@x.test', 'Secreta-2026', Role::Owner);
    $this->actingAs($owner)->get('/')->assertRedirect('/event-panel');
});
