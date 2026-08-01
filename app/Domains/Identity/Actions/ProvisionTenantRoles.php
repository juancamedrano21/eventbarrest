<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Platform\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materializa las plantillas de rol de la plataforma en una cuenta.
 *
 * Los permisos son globales (no llevan tenant_id); lo que pertenece a cada
 * cuenta son los roles y sus asignaciones, y su fuente son las PLANTILLAS
 * (role_templates): las de sistema nacen del código y el superadmin puede
 * ajustarlas o crear más. Es idempotente: volver a ejecutarlo sobre una
 * cuenta al día no toca nada.
 */
class ProvisionTenantRoles
{
    /**
     * Cada plantilla existe como rol y con EXACTAMENTE sus permisos, y no
     * quedan roles huérfanos retirables. La comparación es por conjunto, no
     * por conteo: cambiar un permiso por otro deja el mismo total y aun así
     * hay que resincronizar.
     *
     * @param  Collection<int, RoleTemplate>  $templates
     */
    private function alreadyProvisioned(Tenant $tenant, Collection $templates): bool
    {
        $roles = Role::query()
            ->where('tenant_id', $tenant->id)
            ->with('permissions:id,name')
            ->get()
            ->keyBy('name');

        foreach ($templates as $template) {
            $role = $roles->get($template->name);

            if ($role === null) {
                return false;
            }

            $have = $role->permissions->pluck('name')->sort()->values()->all();
            $want = collect($template->permissions)->sort()->values()->all();

            if ($have !== $want) {
                return false;
            }
        }

        return $this->deletableOrphans($roles, $templates)->isEmpty();
    }

    /**
     * Roles de la cuenta sin plantilla que los respalde y sin titulares:
     * residuos de una plantilla eliminada o de siembras viejas. Con
     * titulares se conservan — retirarlos dejaría usuarios sin rol.
     *
     * @param  Collection<string, Role>  $roles
     * @param  Collection<int, RoleTemplate>  $templates
     * @return Collection<string, Role>
     */
    private function deletableOrphans(Collection $roles, Collection $templates): Collection
    {
        $backed = $templates->pluck('name');

        return $roles
            ->reject(fn (Role $role, string $name): bool => $backed->contains($name))
            ->reject(fn (Role $role): bool => DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->exists());
    }

    /**
     * @param  Collection<int, RoleTemplate>|null  $templates
     */
    public function __invoke(Tenant $tenant, ?Collection $templates = null): void
    {
        if ($templates === null) {
            RoleTemplate::ensureSystemTemplates();
            $templates = RoleTemplate::query()->get();
        }

        // Salida temprana: crear un usuario llama a esto y, si no cambiara
        // nada, vaciaría la caché de permisos de TODA la plataforma.
        if ($this->alreadyProvisioned($tenant, $templates)) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            foreach (PermissionEnum::values() as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            $registrar->setPermissionsTeamId($tenant->id);

            foreach ($templates as $template) {
                $role = Role::findOrCreate($template->name, 'web');
                $role->syncPermissions($template->permissions);
            }

            $roles = Role::query()
                ->where('tenant_id', $tenant->id)
                ->get()
                ->keyBy('name');

            foreach ($this->deletableOrphans($roles, $templates) as $orphan) {
                $orphan->delete();
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
