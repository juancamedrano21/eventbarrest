<?php

declare(strict_types=1);

namespace App\Domains\Operations\Actions;

use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Exceptions\InvalidOperatingUnitException;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Tenancy\TenantContext;

/**
 * Alta de una sucursal. Solo tiene sentido en una cuenta de negocio: un
 * organizador opera con eventos, y sus puntos de venta nacen dentro de uno.
 */
class CreateBranch
{
    public function __invoke(
        string $name,
        OperatingUnitKind $kind = OperatingUnitKind::Mixed,
        OperatingUnitStatus $status = OperatingUnitStatus::Active,
    ): OperatingUnit {
        $tenant = app(TenantContext::class)->currentOrFail();

        if ($tenant->type !== TenantType::Business) {
            throw InvalidOperatingUnitException::wrongAccountType($tenant->type);
        }

        return OperatingUnit::create([
            'name' => $name,
            'kind' => $kind,
            'status' => $status,
        ]);
    }
}
