<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Sin borrado: un producto con ventas históricas no se elimina, se
     * desactiva. El precio congelado de las órdenes lo exige.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
