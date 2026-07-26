<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Identity\Queries\TenantOwners;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class AssignTenantRole
{
    public function __invoke(User $user, RoleEnum $role): User
    {
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            return $user;
        }

        // Un negocio sin dueño se queda sin nadie que pueda administrarlo.
        if ($role !== RoleEnum::Owner && app(TenantOwners::class)->isLastOwner($user)) {
            throw LastOwnerException::cannotDemote($user->name);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($tenantId);
            $user->syncRoles([$role->value]);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        return $user;
    }
}
