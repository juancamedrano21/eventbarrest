<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleTemplates\Pages;

use App\Domains\Identity\Actions\ApplyRoleTemplates;
use App\Domains\Identity\Models\RoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\RoleTemplateResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRoleTemplate extends CreateRecord
{
    protected static string $resource = RoleTemplateResource::class;

    /**
     * El alcance (kind) es identidad, no dato editable: queda fuera de
     * Fillable y se fija aquí, solo en el alta.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $template = new RoleTemplate([
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'],
        ]);

        $template->forceFill([
            'kind' => $data['kind'],
            'is_system' => false,
        ])->save();

        return $template;
    }

    protected function afterCreate(): void
    {
        $cuentas = app(ApplyRoleTemplates::class)();

        Notification::make()
            ->success()
            ->title('Rol creado y aplicado')
            ->body("Disponible ya en {$cuentas} cuenta(s).")
            ->send();
    }
}
