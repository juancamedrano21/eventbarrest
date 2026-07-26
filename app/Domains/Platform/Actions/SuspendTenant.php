<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;

/**
 * Suspender corta el acceso del negocio completo (login de sus usuarios y
 * sincronización de sus POS se rechazan en cuanto esos flujos existan).
 * No borra nada: los datos quedan intactos para una reactivación.
 */
class SuspendTenant
{
    public function __invoke(Tenant $tenant): Tenant
    {
        $tenant->update(['status' => TenantStatus::Suspended]);

        return $tenant;
    }
}
