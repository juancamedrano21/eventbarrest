<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Branches\Pages;

use App\Filament\App\Resources\Branches\BranchResource;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * Sin borrado: una sucursal con ventas o comprobantes fiscales no se
     * elimina, se cierra (estado).
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
