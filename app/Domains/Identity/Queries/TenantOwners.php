<?php

declare(strict_types=1);

namespace App\Domains\Identity\Queries;

use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Quiénes son los dueños de una cuenta.
 *
 * Deliberadamente NO usa la relación roles() de spatie: en modo teams esa
 * relación filtra por el equipo VIGENTE en el ambiente, no por el tenant que
 * se pasa como argumento. Eso hacía que la garantía del último dueño fallara
 * ABIERTA fuera del panel de negocio — el panel de plataforma, los comandos y
 * los jobs corren sin equipo fijado, y allí la consulta devolvía cero dueños
 * y dejaba degradar al único que quedaba.
 *
 * Con las tablas a pelo la respuesta depende solo del argumento, que es lo
 * que una garantía necesita.
 */
class TenantOwners
{
    public function count(int $tenantId): int
    {
        return DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.tenant_id', $tenantId)
            ->where('roles.tenant_id', $tenantId)
            ->where('model_has_roles.tenant_id', $tenantId)
            ->where('roles.name', RoleEnum::Owner->value)
            ->distinct()
            ->count('users.id');
    }

    public function isOwner(User $user): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.tenant_id', $user->tenant_id)
            ->where('roles.tenant_id', $user->tenant_id)
            ->where('roles.name', RoleEnum::Owner->value)
            ->exists();
    }

    public function isLastOwner(User $user): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        return $this->isOwner($user) && $this->count($user->tenant_id) <= 1;
    }
}
