<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Platform\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta de un usuario dentro de un negocio, con su rol.
 *
 * La pertenencia (tenant_id) se fija aquí y nunca por mass assignment, y el
 * rol se asigna con el equipo de spatie apuntando al tenant correcto, de modo
 * que un rol jamás se concede en el negocio equivocado.
 */
class CreateTenantUser
{
    public function __invoke(Tenant $tenant, string $name, string $email, string $password, RoleEnum $role): User
    {
        return DB::transaction(function () use ($tenant, $name, $email, $password, $role): User {
            app(ProvisionTenantRoles::class)($tenant);

            $user = new User;
            $user->forceFill([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_platform_admin' => false,
                'email_verified_at' => now(),
            ])->save();

            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();

            try {
                $registrar->setPermissionsTeamId($tenant->id);
                $user->syncRoles([$role->value]);
            } finally {
                $registrar->setPermissionsTeamId($previousTeam);
            }

            return $user;
        });
    }
}
