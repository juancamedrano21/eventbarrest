<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FoodTypes\Pages;

use App\Filament\Admin\Resources\FoodTypes\FoodTypesResource;
use Filament\Resources\Pages\ListRecords;

class ListFoodTypes extends ListRecords
{
    protected static string $resource = FoodTypesResource::class;
}
