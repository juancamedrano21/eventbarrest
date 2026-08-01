<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorTypes\Pages;

use App\Filament\Admin\Resources\VendorTypes\VendorTypesResource;
use Filament\Resources\Pages\ListRecords;

class ListVendorTypes extends ListRecords
{
    protected static string $resource = VendorTypesResource::class;
}
