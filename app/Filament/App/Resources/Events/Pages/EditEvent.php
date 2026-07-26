<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\Pages;

use App\Filament\App\Resources\Events\EventResource;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Sin borrado: un evento con ventas y comprobantes fiscales se cierra y se
     * liquida, no se elimina.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
