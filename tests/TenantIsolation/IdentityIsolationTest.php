<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Models\User;

/**
 * User no lleva BelongsToTenant a propósito (el login ocurre sin contexto),
 * así que su aislamiento no lo da el global scope: lo dan el middleware y la
 * comprobación explícita de cada pantalla. Esta suite es el contrato de esa
 * garantía, y se pega por HTTP contra la puerta que administra el equipo.
 */
beforeEach(function (): void {
    $this->barA = app(CreateTenant::class)('Bar A');
    $this->barB = app(CreateTenant::class)('Bar B');

    $this->ownerA = app(CreateTenantUser::class)($this->barA, 'Ana', 'ana@a.test', 'Secreta-2026', Role::Owner);
    $this->ownerB = app(CreateTenantUser::class)($this->barB, 'Beto', 'beto@b.test', 'Secreta-2026', Role::Owner);
    $this->cashierA = app(CreateTenantUser::class)(
        $this->barA, 'Carla', 'carla@a.test', 'Secreta-2026', Role::Cashier, null, null, 'carla',
    );

    actAsTenantPermissions($this->barA->id);
});

it('scopes the team list to the users own tenant', function (): void {
    $this->actingAs($this->ownerA)
        ->get('/business/equipo')
        ->assertOk()
        ->assertSee('ana@a.test')
        ->assertSee('carla@a.test')
        ->assertDontSee('beto@b.test');
});

it('hides platform staff from the tenant team list', function (): void {
    $staff = User::factory()->platformAdmin()->create([
        'name' => 'Soporte', 'email' => 'soporte@plataforma.test',
    ]);

    $this->actingAs($this->ownerA)
        ->get('/business/equipo')
        ->assertOk()
        ->assertDontSee($staff->email);
});

it('refuses to touch another tenants user, even by id', function (): void {
    $this->actingAs($this->ownerA)
        ->post("/business/equipo/{$this->ownerB->id}", [
            'name' => 'Robado', 'email' => 'beto@b.test',
            'password' => '', 'role' => Role::Warehouse->value,
        ])
        ->assertNotFound();

    expect($this->ownerB->fresh()->name)->toBe('Beto');
});

it('grants roles only within the tenant that issued them', function (): void {
    // El mismo nombre de rol existe en ambos negocios: la asignación de A
    // no puede conceder nada en B.
    actAsTenantPermissions($this->barB->id);
    expect($this->ownerA->fresh()->hasRole(Role::Owner->value))->toBeFalse();

    actAsTenantPermissions($this->barA->id);
    expect($this->ownerA->fresh()->hasRole(Role::Owner->value))->toBeTrue();
});

it('gives each role the permissions of the matrix', function (): void {
    actAsTenantPermissions($this->barA->id);

    expect($this->ownerA->fresh()->can(Permission::UsersManage->value))->toBeTrue()
        ->and($this->cashierA->fresh()->can(Permission::SalesOperate->value))->toBeTrue()
        ->and($this->cashierA->fresh()->can(Permission::UsersManage->value))->toBeFalse()
        ->and($this->cashierA->fresh()->can(Permission::SalesVoid->value))->toBeFalse();
});

it('keeps a cashier out of the team screen entirely', function (): void {
    // Su trabajo ocurre en la caja: se le manda allí, no a un 403 seco.
    $this->actingAs($this->cashierA)->get('/business/equipo')->assertRedirect('/pos');
});
