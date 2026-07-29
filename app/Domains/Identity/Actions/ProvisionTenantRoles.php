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
    /** Todos los roles existen y cada uno tiene exactamente sus permisos. */
    private function alreadyProvisioned(Tenant $tenant): bool
    {
        $roles = Role::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('permissions')
            ->get()
            ->keyBy('name');

        foreach (RoleEnum::cases() as $case) {
            $role = $roles->get($case->value);

            if ($role === null || $role->permissions_count !== count($case->permissions())) {
                return false;
            }
        }

        return true;
    }

    public function __invoke(Tenant $tenant): void
    {
        // Salida temprana: crear un usuario llamaba a esto y, aunque no
        // cambiara nada, vaciaba la caché de permisos de TODA la plataforma.
        if ($this->alreadyProvisioned($tenant)) {
            return;
        }

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
