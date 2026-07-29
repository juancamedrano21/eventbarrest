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

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            // El equipo se fija ANTES de cualquier comprobación: la acción no
            // hereda el del ambiente, que fuera del panel de negocio puede no
            // existir (panel de plataforma, comandos, jobs).
            $registrar->setPermissionsTeamId($tenantId);

            // Una cuenta sin dueño se queda sin nadie que pueda administrarla.
            if ($role !== RoleEnum::Owner && app(TenantOwners::class)->isLastOwner($user)) {
                throw LastOwnerException::cannotDemote($user->name);
            }

            $user->syncRoles([$role->value]);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        return $user;
    }
}
