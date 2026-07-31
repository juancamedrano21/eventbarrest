<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Tables;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Product;
use App\Domains\EventManagement\Models\Vendor;
use App\Filament\App\Support\VendorPanel;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['category', 'inventoryItem', 'recipeItems.inventoryItem']))
            ->columns([
                TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vendor.name')
                    ->label('Comercio')
                    ->placeholder('De la cuenta')
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('price_cents')
                    ->label('Precio')
                    ->money('DOP', divideBy: 100)
                    ->sortable(),
                TextColumn::make('cost')
                    ->label('Costo')
                    ->state(fn (Product $record): ?int => $record->costCents())
                    ->money('DOP', divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('margin')
                    ->label('Margen')
                    ->state(fn (Product $record): ?float => $record->marginPercent())
                    ->suffix(' %')
                    ->color(fn (?float $state): ?string => match (true) {
                        $state === null => null,
                        $state < 0.0 => 'danger',
                        $state < 30.0 => 'warning',
                        default => 'success',
                    })
                    ->placeholder('—'),
                IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Comercio')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                SelectFilter::make('category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name'),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(ProductType::class),
                TernaryFilter::make('active')
                    ->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Aún no hay productos')
            ->emptyStateDescription('Crea el catálogo: lo que se vende, con su precio y su receta.');
    }
}
