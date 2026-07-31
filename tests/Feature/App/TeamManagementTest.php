<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Platform\Actions\CreateTenant;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto');
    $this->owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);

    // El mismo camino que el middleware: con TenantContext fijado, para que
    // el formulario decida sus campos con el contexto real del panel.
    signInTo($this, $this->owner, $this->tenant);
});

it('creates a team member inside the owners tenant', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Carla',
            'email' => 'carla@bar.test',
            'password' => 'Secreta-2026',
            'role' => Role::Cashier->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $carla = User::query()->where('email', 'carla@bar.test')->sole();

    expect($carla->tenant_id)->toBe($this->tenant->id)
        ->and($carla->is_platform_admin)->toBeFalse()
        ->and($carla->hasRole(Role::Cashier->value))->toBeTrue()
        ->and(Hash::check('Secreta-2026', $carla->password))->toBeTrue();
});

it('rejects a duplicate email', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Otra Ana',
            'email' => 'ana@bar.test',
            'password' => 'Secreta-2026',
            'role' => Role::Cashier->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

it('rejects a weak password', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Carla',
            'email' => 'carla@bar.test',
            'password' => '123',
            'role' => Role::Cashier->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('changes a members role', function (): void {
    $carla = app(CreateTenantUser::class)($this->tenant, 'Carla', 'carla@bar.test', 'Secreta-2026', Role::Cashier);

    Livewire::test(EditUser::class, ['record' => $carla->getRouteKey()])
        ->fillForm(['role' => Role::UnitManager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($carla->fresh()->hasRole(Role::UnitManager->value))->toBeTrue()
        ->and($carla->fresh()->hasRole(Role::Cashier->value))->toBeFalse();
});

it('keeps the password when the field is left empty on edit', function (): void {
    $carla = app(CreateTenantUser::class)($this->tenant, 'Carla', 'carla@bar.test', 'Secreta-2026', Role::Cashier);

    Livewire::test(EditUser::class, ['record' => $carla->getRouteKey()])
        ->fillForm(['name' => 'Carla M.', 'password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($carla->fresh()->name)->toBe('Carla M.')
        ->and(Hash::check('Secreta-2026', $carla->fresh()->password))->toBeTrue();
});

it('refuses to demote the only owner', function (): void {
    app(AssignTenantRole::class)($this->owner, Role::Cashier);
})->throws(LastOwnerException::class);

it('allows demoting an owner once another one exists', function (): void {
    app(CreateTenantUser::class)($this->tenant, 'Beto', 'beto@bar.test', 'Secreta-2026', Role::Owner);

    app(AssignTenantRole::class)($this->owner, Role::Admin);

    expect($this->owner->fresh()->hasRole(Role::Admin->value))->toBeTrue();
});

it('hides the delete action for your own account', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->owner->getRouteKey()])
        ->assertActionHidden('delete');
});
