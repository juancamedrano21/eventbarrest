<?php

declare(strict_types=1);

namespace App\Domains\Identity\Queries;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los roles de un usuario en SU cuenta, sin depender del equipo de permisos
 * que haya en el ambiente.
 *
 * Hace falta porque hay momentos legítimos en los que el equipo aún no está
 * fijado y sin embargo necesitamos saber el rol: el más claro es
 * canAccessPanel(), que Filament evalúa al autenticar — antes de que corra
 * el middleware que fija el contexto. Ahí getRoleNames() devuelve vacío y
 * cualquier decisión basada en él falla en silencio.
 */
class UserRoles
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
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.tenant_id', $user->tenant_id)
            ->where('roles.tenant_id', $user->tenant_id)
            ->pluck('roles.name')
            ->all());
    }
}
