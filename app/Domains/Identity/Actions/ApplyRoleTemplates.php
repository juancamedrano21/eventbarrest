<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Propaga las plantillas de rol a TODAS las cuentas de la plataforma. Se
 * invoca al guardar una plantilla desde /admin: el cambio del superadmin
 * llega a cada cuenta en el acto.
 *
 * Cada cuenta va en su propia transacción: un corte a mitad deja cuentas
 * completamente viejas o completamente nuevas, nunca a medio sincronizar —
 * y el reintento (volver a guardar, o identity:provision-roles) es
 * idempotente. Las cuentas ya al día cuestan unas pocas consultas por la
 * salida temprana del aprovisionamiento.
 */
class ApplyRoleTemplates
{
    public function __invoke(): int
    {
        RoleTemplate::ensureSystemTemplates();
        $templates = RoleTemplate::query()->get();

        $applied = 0;

        Tenant::query()->chunkById(100, function ($tenants) use ($templates, &$applied): void {
            foreach ($tenants as $tenant) {
                DB::transaction(fn () => app(ProvisionTenantRoles::class)($tenant, $templates));
                $applied++;
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $applied;
    }
}
