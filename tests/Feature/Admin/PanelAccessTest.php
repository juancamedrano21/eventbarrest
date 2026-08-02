<?php

declare(strict_types=1);

use App\Models\User;

/**
 * /saas-admin es el único panel Filament que queda: el de la plataforma.
 * Los paneles de cliente son Blade y su acceso se prueba en su propia puerta.
 */
it('redirects guests to the panel login', function (): void {
    $this->get('/saas-admin')->assertRedirect('/saas-admin/login');
});

it('forbids users without a tenant and without platform rights', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/saas-admin')->assertForbidden();
});

it('lets platform admins into the platform panel', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get('/saas-admin')->assertOk();
});

it('sends platform staff away from the client panels', function (): void {
    // El staff no pertenece a ningún negocio: entrar sería entrar «en el
    // negocio de nadie». Para asistir a una cuenta se usará la suplantación
    // auditada, no un acceso directo.
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get('/business')->assertRedirect('/saas-admin');
    $this->get('/event-vendor')->assertRedirect('/event-panel');
});
