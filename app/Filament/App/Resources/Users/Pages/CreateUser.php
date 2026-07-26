<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Pages;

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Filament\App\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * La pertenencia al negocio la fija la acción de dominio a partir del
     * usuario autenticado; nunca llega desde el formulario.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::auth()->user()?->tenant;

        abort_if($tenant === null, 403);

        return app(CreateTenantUser::class)(
            $tenant,
            $data['name'],
            $data['email'],
            $data['password'],
            RoleEnum::coerce($data['role']),
        );
    }
}
