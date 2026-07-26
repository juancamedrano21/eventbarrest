<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Platform\Actions\CreateTenant;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Filament\App\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * User no lleva BelongsToTenant a propósito (el login ocurre sin contexto),
 * así que su aislamiento no lo da el global scope: lo dan el middleware y el
 * Resource. Esta suite es el contrato de esa garantía.
 */
beforeEach(function (): void {
    $this->barA = app(CreateTenant::class)('Bar A');
    $this->barB = app(CreateTenant::class)('Bar B');

    $this->ownerA = app(CreateTenantUser::class)($this->barA, 'Ana', 'ana@a.test', 'Secreta-2026', Role::Owner);
    $this->ownerB = app(CreateTenantUser::class)($this->barB, 'Beto', 'beto@b.test', 'Secreta-2026', Role::Owner);
    $this->cashierA = app(CreateTenantUser::class)($this->barA, 'Carla', 'carla@a.test', 'Secreta-2026', Role::Cashier);

    // Los tests de Livewire montan el componente sin pasar por el middleware,
    // así que el equipo de permisos se fija a mano. El camino HTTP completo
    // (con SetTenantContext) se cubre en Feature/App/TenantPanelAccessTest.
    actAsTenantPermissions($this->barA->id);
});

it('scopes the team list to the users own tenant', function (): void {
    $this->actingAs($this->ownerA);
    Filament::setCurrentPanel('app');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->ownerA, $this->cashierA])
        ->assertCanNotSeeTableRecords([$this->ownerB]);
});

it('hides platform staff from the tenant team list', function (): void {
    $staff = User::factory()->platformAdmin()->create(['name' => 'Soporte']);

    $this->actingAs($this->ownerA);
    Filament::setCurrentPanel('app');

    Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$staff]);
});

it('refuses to open another tenants user for editing', function (): void {
    $this->actingAs($this->ownerA);
    Filament::setCurrentPanel('app');

    Livewire::test(EditUser::class, ['record' => $this->ownerB->getRouteKey()]);
})->throws(ModelNotFoundException::class);

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
    $this->actingAs($this->cashierA);
    Filament::setCurrentPanel('app');

    Livewire::test(ListUsers::class)->assertForbidden();
});
