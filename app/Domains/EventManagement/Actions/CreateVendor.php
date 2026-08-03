<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Actions;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Kitchen\Actions\IssueVendorKdsCode;

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
        $vendor = Vendor::create([
            'name' => $name,
            'rnc' => $rnc,
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'status' => $status,
        ]);

        // Nace con su código de tablet. Se emite aquí y no cuando alguien
        // abre la pantalla del KDS para que ningún comercio pueda existir
        // sin él: el código es lo que se imprime en la hoja del puesto, y
        // esa hoja se prepara mucho antes de que nadie piense en cocina.
        app(IssueVendorKdsCode::class)($vendor);

        return $vendor;
    }
}
