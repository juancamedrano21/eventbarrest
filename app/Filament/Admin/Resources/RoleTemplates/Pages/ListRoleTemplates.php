<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleTemplates\Pages;

use App\Filament\Admin\Resources\RoleTemplates\RoleTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoleTemplates extends ListRecords
{
    protected static string $resource = RoleTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo rol'),
        ];
    }
}
