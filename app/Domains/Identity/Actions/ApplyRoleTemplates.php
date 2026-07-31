<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Platform\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Propaga las plantillas de rol a TODAS las cuentas de la plataforma. Se
 * invoca al guardar una plantilla desde /admin: el cambio del superadmin
 * llega a cada cuenta en el acto. El aprovisionamiento por cuenta tiene
 * salida temprana, así que las cuentas ya al día cuestan una consulta.
 */
class ApplyRoleTemplates
{
    public function __invoke(): int
    {
        $applied = 0;

        foreach (Tenant::query()->get() as $tenant) {
            app(ProvisionTenantRoles::class)($tenant);
            $applied++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $applied;
    }
}
