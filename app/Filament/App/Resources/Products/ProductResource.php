<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Products;

use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Enums\Permission;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Schemas\ProductForm;
use App\Filament\App\Resources\Products\Tables\ProductsTable;
use App\Filament\App\Support\VendorPanel;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * El catálogo sirve a los dos mundos, pero no igual: en una cuenta de
 * negocio lo administra el equipo de la cuenta; en una de organizador
 * pertenece a los comercios del evento — solo su personal lo escribe, y el
 * organizador lo mira consolidado.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'producto';

    protected static ?string $pluralModelLabel = 'productos';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(Permission::CatalogManage->value) === true;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && VendorPanel::writesAllowed();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && VendorPanel::writesAllowed();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
