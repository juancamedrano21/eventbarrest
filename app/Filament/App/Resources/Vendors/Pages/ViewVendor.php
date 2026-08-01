<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors\Pages;

use App\Filament\App\Resources\Vendors\VendorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * El perfil del comercio: desde aquí se vive todo lo suyo — su equipo, sus
 * eventos con comisión, sus puestos y su catálogo. El organizador relaciona
 * y mira; la operación (catálogo, stock, ventas) es del propio comercio.
 */
class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar datos'),
        ];
    }
}
