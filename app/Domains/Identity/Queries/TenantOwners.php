<?php

declare(strict_types=1);

namespace App\Domains\Identity\Queries;

use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Models\User;

/**
 * Consulta única para "quiénes son los dueños de este negocio".
 *
 * Las columnas van cualificadas porque `users`, `roles` y `model_has_roles`
 * tienen todas una columna `tenant_id`: sin cualificar, MySQL/SQLite fallan
 * por ambigüedad.
 */
class TenantOwners
{
    public function count(int $tenantId): int
    {
        return User::query()
            ->where('users.tenant_id', $tenantId)
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.name', RoleEnum::Owner->value)
                ->where('roles.tenant_id', $tenantId))
            ->count();
    }

    public function isLastOwner(User $user): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        $isOwner = $user->roles()
            ->where('roles.name', RoleEnum::Owner->value)
            ->where('roles.tenant_id', $user->tenant_id)
            ->exists();

        return $isOwner && $this->count($user->tenant_id) <= 1;
    }
}
