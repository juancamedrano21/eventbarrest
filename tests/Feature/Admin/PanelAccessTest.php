<?php

declare(strict_types=1);

use App\Models\User;

it('redirects guests to the panel login', function (string $panel): void {
    $this->get("/{$panel}")->assertRedirect("/{$panel}/login");
})->with(['admin', 'app']);

it('forbids users without a tenant and without platform rights', function (string $panel): void {
    $this->actingAs(User::factory()->create());

    $this->get("/{$panel}")->assertForbidden();
})->with(['admin', 'app']);

it('lets platform admins into the platform panel', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get('/admin')->assertOk();
});

it('keeps platform staff out of the business panel', function (): void {
    // El staff no pertenece a ningún negocio: entrar en /app sería entrar
    // "en el negocio de nadie". Para asistir a un tenant se usará la
    // suplantación auditada, no un acceso directo.
    $this->actingAs(User::factory()->platformAdmin()->create());

    $this->get('/app')->assertForbidden();
});
