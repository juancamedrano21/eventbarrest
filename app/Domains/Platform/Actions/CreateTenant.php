<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Identity\Actions\ProvisionTenantRoles;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un negocio. Sus roles se crean en el mismo movimiento para que
 * nunca exista un tenant sin el juego de roles que su equipo necesita.
 */
class CreateTenant
{
    public function __invoke(string $name, ?string $rnc = null, TenantStatus $status = TenantStatus::Trial): Tenant
    {
        return DB::transaction(function () use ($name, $rnc, $status): Tenant {
            $tenant = Tenant::create([
                'name' => $name,
                'rnc' => $rnc,
                'status' => $status,
            ]);

            app(ProvisionTenantRoles::class)($tenant);

            return $tenant;
        });
    }
}
