<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleTemplates\Pages;

use App\Domains\Identity\Actions\ApplyRoleTemplates;
use App\Domains\Identity\Models\RoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\RoleTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRoleTemplate extends EditRecord
{
    protected static string $resource = RoleTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (RoleTemplate $record, DeleteAction $action): void {
                    if ($record->assignedUsersCount() > 0) {
                        Notification::make()
                            ->danger()
                            ->title('No se puede eliminar')
                            ->body("[{$record->label}] tiene usuarios asignados: quítaselo antes de eliminarlo.")
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    protected function afterSave(): void
    {
        $cuentas = app(ApplyRoleTemplates::class)();

        Notification::make()
            ->success()
            ->title('Cambios aplicados')
            ->body("Los límites del rol se propagaron a {$cuentas} cuenta(s).")
            ->send();
    }
}
