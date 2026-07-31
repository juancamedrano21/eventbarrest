<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\EventManagement\Exceptions\VendorException;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Identity\Queries\TenantOwners;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class AssignTenantRole
{
    public function __invoke(User $user, RoleEnum|string $role): User
    {
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            return $user;
        }

        $template = RoleTemplate::resolveOrFail($role instanceof RoleEnum ? $role->value : $role);

        // Mismas fronteras que en el alta: el personal de un comercio no
        // asciende a un rol de cuenta cambiándole el rol después.
        if ($user->vendor_id !== null && ! $template->kind->assignableToVendorStaff()) {
            throw VendorException::roleNotForVendorStaff($template->name);
        }

        if ($user->vendor_id === null && ! $template->kind->assignableToAccountStaff()) {
            throw VendorException::roleOnlyForVendorStaff($template->name);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            // El equipo se fija ANTES de cualquier comprobación: la acción no
            // hereda el del ambiente, que fuera del panel de negocio puede no
            // existir (panel de plataforma, comandos, jobs).
            $registrar->setPermissionsTeamId($tenantId);

            // Una cuenta sin dueño se queda sin nadie que pueda administrarla.
            if ($template->name !== RoleEnum::Owner->value && app(TenantOwners::class)->isLastOwner($user)) {
                throw LastOwnerException::cannotDemote($user->name);
            }

            // Idempotente y con salida temprana: garantiza que el rol exista
            // aunque la cuenta se haya aprovisionado antes de que ese rol
            // naciera (backfills, imports, tinker) — syncRoles no lo crea.
            if ($user->tenant !== null) {
                app(ProvisionTenantRoles::class)($user->tenant);
            }

            $user->syncRoles([$template->name]);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        return $user;
    }
}
