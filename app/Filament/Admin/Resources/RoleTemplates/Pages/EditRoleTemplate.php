<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleTemplates\Pages;

use App\Domains\Identity\Actions\ApplyRoleTemplates;
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
            RoleTemplateResource::configureDeleteAction(DeleteAction::make()),
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
