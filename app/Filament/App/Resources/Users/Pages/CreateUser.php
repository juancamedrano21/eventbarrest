<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Pages;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Filament\App\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * La pertenencia a la cuenta la fija la acción de dominio a partir del
     * usuario autenticado; nunca llega desde el formulario. El comercio sí
     * viene del formulario, pero se resuelve con el scope de tenant activo:
     * un id ajeno simplemente no se encuentra.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::auth()->user()?->tenant;

        abort_if($tenant === null, 403);

        $vendorId = $data['vendor_id'] ?? null;

        return app(CreateTenantUser::class)(
            $tenant,
            $data['name'],
            $data['email'],
            $data['password'],
            (string) $data['role'],
            $vendorId === null ? null : Vendor::query()->findOrFail((int) $vendorId),
            username: $data['username'] ?? null,
        );
    }
}
