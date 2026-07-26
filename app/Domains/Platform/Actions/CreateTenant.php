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
            $tenant = new Tenant([
                'name' => $name,
                'rnc' => $rnc,
                'status' => $status,
            ]);

            // El tipo no es fillable: define el mundo de la cuenta y solo se
            // fija aquí, al darla de alta.
            $tenant->type = $type;
            $tenant->save();

            app(ProvisionTenantRoles::class)($tenant);

            return $tenant;
        });
    }
}
