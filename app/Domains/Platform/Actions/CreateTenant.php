<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Identity\Actions\ProvisionTenantRoles;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un negocio. Sus roles se crean en el mismo movimiento para que
 * nunca exista un tenant sin el juego de roles que su equipo necesita.
 */
class CreateTenant
{
    public function __invoke(
        string $name,
        ?string $rnc = null,
        TenantType $type = TenantType::Business,
        TenantStatus $status = TenantStatus::Trial,
    ): Tenant {
        return DB::transaction(function () use ($name, $rnc, $type, $status): Tenant {
            // La clase del mundo fija su propio tipo al nacer: aquí solo se
            // elige qué mundo se está dando de alta.
            $tenant = $type->accountClass()::create([
                'name' => $name,
                'rnc' => $rnc,
                'status' => $status,
            ]);

            app(ProvisionTenantRoles::class)($tenant);

            return $tenant;
        });
    }
}
