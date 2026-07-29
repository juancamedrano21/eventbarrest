<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Identity\Queries\TenantOwners;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * La garantía del último dueño no puede depender del equipo de permisos que
 * haya en el ambiente: el panel de plataforma, los comandos y los jobs corren
 * sin equipo fijado, y ahí la comprobación fallaba ABIERTA — dejaba degradar
 * al único dueño y la cuenta quedaba sin nadie que pudiera administrarla.
 */
beforeEach(function (): void {
    $this->tenant = app(CreateTenant::class)('Bar del Puerto', null, TenantType::Business);
    $this->owner = app(CreateTenantUser::class)($this->tenant, 'Ana', 'ana@bar.test', 'Secreta-2026', Role::Owner);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('counts owners regardless of the ambient team', function (): void {
    $owners = app(TenantOwners::class);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    $withTeam = $owners->count($this->tenant->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $withoutTeam = $owners->count($this->tenant->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId(99999);
    $withWrongTeam = $owners->count($this->tenant->id);

    expect($withTeam)->toBe(1)
        ->and($withoutTeam)->toBe(1)
        ->and($withWrongTeam)->toBe(1);
});

it('refuses to demote the only owner with no ambient team', function (): void {
    // Exactamente el estado del panel de plataforma: staff sin tenant, así
    // que nadie fijó el equipo de permisos.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(AssignTenantRole::class)($this->owner, Role::Cashier);
})->throws(LastOwnerException::class);

it('refuses to demote the only owner with a foreign team set', function (): void {
    $otro = app(CreateTenant::class)('Otro Bar', null, TenantType::Business);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otro->id);

    app(AssignTenantRole::class)($this->owner, Role::Cashier);
})->throws(LastOwnerException::class);

it('leaves the account with its owner after a refused demotion', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    try {
        app(AssignTenantRole::class)($this->owner, Role::Cashier);
    } catch (LastOwnerException) {
        // esperado
    }

    expect(app(TenantOwners::class)->count($this->tenant->id))->toBe(1);
});

it('allows the demotion once a second owner exists, from any team state', function (): void {
    app(CreateTenantUser::class)($this->tenant, 'Beto', 'beto@bar.test', 'Secreta-2026', Role::Owner);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(AssignTenantRole::class)($this->owner, Role::Admin);

    expect(app(TenantOwners::class)->count($this->tenant->id))->toBe(1)
        ->and(app(TenantOwners::class)->isOwner($this->owner->fresh()))->toBeFalse();
});
