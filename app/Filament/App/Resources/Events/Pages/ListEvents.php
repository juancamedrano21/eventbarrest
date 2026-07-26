<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\Pages;

use App\Filament\App\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo evento'),
        ];
    }
}
