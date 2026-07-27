<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\StockLevels\Pages;

use App\Filament\App\Resources\StockLevels\StockLevelResource;
use Filament\Resources\Pages\ListRecords;

class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;
}
