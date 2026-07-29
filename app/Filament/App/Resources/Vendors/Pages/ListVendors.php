<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors\Pages;

use App\Filament\App\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;
}
