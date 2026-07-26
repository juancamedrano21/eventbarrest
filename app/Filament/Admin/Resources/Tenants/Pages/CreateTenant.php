<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Domains\Platform\Actions\CreateTenant as CreateTenantAction;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Delegado en la acción de dominio: crear el negocio y aprovisionar sus
     * roles es una sola operación, dentro de la misma transacción.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateTenantAction::class)(
            $data['name'],
            $data['rnc'] ?? null,
            TenantType::coerce($data['type']),
            TenantStatus::coerce($data['status']),
        );
    }
}
