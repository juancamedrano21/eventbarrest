<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Platform\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea el juego de roles de un negocio recién dado de alta.
 *
 * Los permisos son globales (no llevan tenant_id); lo que pertenece a cada
 * negocio son los roles y sus asignaciones. Es idempotente: volver a
 * ejecutarlo sobre un tenant existente no duplica ni pisa nada.
 */
class ProvisionTenantRoles
{
    public function __invoke(Tenant $tenant): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            foreach (PermissionEnum::values() as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            $registrar->setPermissionsTeamId($tenant->id);

            foreach (RoleEnum::cases() as $case) {
                $role = Role::findOrCreate($case->value, 'web');
                $role->syncPermissions($case->permissions());
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
