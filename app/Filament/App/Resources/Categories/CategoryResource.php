<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Categories;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Categories\Pages\ListCategories;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Recurso simple (una sola página con modales): las categorías son un
 * diccionario, no una entidad con vida propia.
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'categoría';

    protected static ?string $pluralModelLabel = 'categorías';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(Permission::CatalogManage->value) === true;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->placeholder('Cócteles')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: Category::class,
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('tenant_id', app(TenantContext::class)->id()),
                )
                ->validationMessages(['unique' => 'Ya existe una categoría con ese nombre.']),
            Select::make('dispatch')
                ->label('Sale de')
                ->options(DispatchArea::class)
                ->default(DispatchArea::Bar)
                ->required()
                ->helperText('Decide qué POS la muestra y por qué impresora saldrán las comandas.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Categoría')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('dispatch')
                    ->label('Sale de')
                    ->badge(),
                TextColumn::make('products_count')
                    ->label('Productos')
                    ->counts('products')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('dispatch')
                    ->label('Sale de')
                    ->options(DispatchArea::class),
            ])
            ->headerActions([
                CreateAction::make()->label('Nueva categoría'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Aún no hay categorías')
            ->emptyStateDescription('Cervezas, cócteles, platos fuertes: agrupa lo que vendes.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
        ];
    }
}
