<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors\RelationManagers;

use App\Domains\Identity\Enums\Permission;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * El catálogo del comercio, en SOLO LECTURA: el organizador mira, el
 * comercio opera — su encargado lo construye desde su propio panel.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Catálogo (lo administra el comercio)';

    protected static ?string $modelLabel = 'producto';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Filament::auth()->user()?->can(Permission::CatalogManage->value) === true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Producto')->searchable(),
                TextColumn::make('category.name')->label('Categoría'),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('price_cents')->label('Precio')->money('DOP', divideBy: 100),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->emptyStateHeading('El comercio aún no tiene catálogo')
            ->emptyStateDescription('Su encargado lo construye al entrar con su propio usuario.');
    }
}
