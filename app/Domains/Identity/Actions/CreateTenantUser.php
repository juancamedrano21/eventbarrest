<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Platform\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta de un usuario dentro de una cuenta, con su rol y — en cuentas de
 * organizador — opcionalmente adscrito a un comercio del evento.
 *
 * La pertenencia (tenant_id, vendor_id) se fija aquí y nunca por mass
 * assignment, y el rol se asigna con el equipo de spatie apuntando al tenant
 * correcto, de modo que un rol jamás se concede en la cuenta equivocada.
 */
class CreateTenantUser
{
    public function __invoke(
        Tenant $tenant,
        string $name,
        string $email,
        string $password,
        RoleEnum $role,
        ?Vendor $vendor = null,
    ): User {
        if ($vendor !== null && $vendor->tenant_id !== $tenant->id) {
            throw VendorException::userOutsideTenant();
        }

        // El personal de un comercio tiene roles de comercio; los roles de
        // cuenta (dueño, administrador, gerente de eventos) no bajan ahí — y
        // el encargado de comercio no existe suelto en la cuenta.
        if ($vendor !== null && ! $role->isForVendorStaff()) {
            throw VendorException::roleNotForVendorStaff($role->value);
        }

        if ($vendor === null && $role === RoleEnum::VendorManager) {
            throw VendorException::roleOnlyForVendorStaff($role->value);
        }

        return DB::transaction(function () use ($tenant, $name, $email, $password, $role, $vendor): User {
            app(ProvisionTenantRoles::class)($tenant);

            $user = new User;
            $user->forceFill([
                'tenant_id' => $tenant->id,
                'vendor_id' => $vendor?->id,
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
