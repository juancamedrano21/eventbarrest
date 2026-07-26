<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Sin borrado: dar de baja un negocio es suspenderlo. Sus ventas y
     * comprobantes fiscales tienen que sobrevivir al negocio mismo, así que
     * un hard delete nunca es la operación correcta aquí.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
