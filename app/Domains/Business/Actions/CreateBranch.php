<?php

declare(strict_types=1);

namespace App\Domains\Business\Actions;

use App\Domains\Business\Models\Branch;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;

/**
 * Alta de una sucursal en la cuenta de negocio activa. Sin condicionales de
 * mundo: Branch solo sabe nacer como sucursal, y su modelo rechaza cuentas
 * que no sean de negocio.
 */
class CreateBranch
{
    public function __invoke(
        string $name,
        OperatingUnitKind $kind = OperatingUnitKind::Mixed,
        OperatingUnitStatus $status = OperatingUnitStatus::Active,
    ): Branch {
        return Branch::create([
            'name' => $name,
            'kind' => $kind,
            'status' => $status,
        ]);
    }
}
