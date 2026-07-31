<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Pages;

use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Exceptions\LastOwnerException;
use App\Domains\Identity\Queries\TenantOwners;
use App\Filament\App\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Eliminar usuario')
                ->visible(fn (User $record): bool => $record->isNot(Filament::auth()->user()))
                ->before(function (User $record, DeleteAction $action): void {
                    if ($this->isLastOwner($record)) {
                        Notification::make()
                            ->danger()
                            ->title('No se puede eliminar')
                            ->body(LastOwnerException::cannotDelete($record->name)->getMessage())
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['role'] = $record instanceof User
            ? $record->getRoleNames()->first()
            : null;

        return $data;
    }

    /**
     * El rol viaja fuera del modelo: lo aplica la acción de dominio, que es
     * quien conoce la regla del último dueño.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = $data['role'] ?? null;
        unset($data['role']);

        // El rol primero: si sus guards lo rechazan (último dueño, rol de
        // cuenta sobre personal de comercio), no queda una escritura parcial
        // de nombre y correo ya aplicada.
        if ($role !== null && $record instanceof User) {
            app(AssignTenantRole::class)($record, RoleEnum::coerce($role));
        }

        $record->update($data);

        return $record;
    }

    private function isLastOwner(User $user): bool
    {
        return app(TenantOwners::class)->isLastOwner($user);
    }
}
