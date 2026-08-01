<?php

declare(strict_types=1);

namespace App\Domains\Identity\Queries;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los permisos efectivos de un usuario en SU cuenta (la unión de los de sus
 * roles), sin depender del equipo de permisos del ambiente: hay decisiones
 * que se toman antes de que el middleware fije el equipo (canAccessPanel) o
 * desde otro panel (/admin).
 */
class UserPermissions
{
    /**
     * @return Collection<int, string>
     */
    public function namesFor(User $user): Collection
    {
        if ($user->tenant_id === null) {
            return collect();
        }

        return collect(DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.tenant_id', $user->tenant_id)
            ->where('roles.tenant_id', $user->tenant_id)
            ->distinct()
            ->pluck('permissions.name')
            ->all());
    }
}
