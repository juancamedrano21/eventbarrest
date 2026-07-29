<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;

/**
 * Alta de un negocio participante en la cuenta de organizador activa. El
 * modelo Vendor rechaza cuentas que no sean de organizador.
 */
class CreateVendor
{
    public function __invoke(
        string $name,
        ?string $rnc = null,
        ?string $contactName = null,
        ?string $contactPhone = null,
        VendorStatus $status = VendorStatus::Active,
    ): Vendor {
        return Vendor::create([
            'name' => $name,
            'rnc' => $rnc,
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'status' => $status,
        ]);
    }
}
