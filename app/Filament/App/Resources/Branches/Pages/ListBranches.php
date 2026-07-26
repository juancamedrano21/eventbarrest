<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Branches\Pages;

use App\Filament\App\Resources\Branches\BranchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva sucursal'),
        ];
    }
}
