<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Branches;

use App\Domains\Business\Models\Branch;
use App\Domains\Business\Models\BusinessAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Exceptions\MissingPermissionException;
use App\Filament\App\Resources\Branches\Pages\CreateBranch;
use App\Filament\App\Resources\Branches\Pages\EditBranch;
use App\Filament\App\Resources\Branches\Pages\ListBranches;
use App\Filament\App\Resources\Branches\Schemas\BranchForm;
use App\Filament\App\Resources\Branches\Tables\BranchesTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Sucursales: el mundo de las cuentas de negocio. Un organizador no ve esta
 * sección — sus puntos de venta viven dentro de cada evento.
 */
class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'sucursal';

    protected static ?string $pluralModelLabel = 'sucursales';

    protected static ?string $navigationLabel = 'Sucursales';

    protected static ?int $navigationSort = 10;

    /**
     * Dos condiciones, no una: estar en el mundo correcto (cuenta de negocio)
     * y tener el permiso de la matriz del documento 04. Un cajero pertenece a
     * la cuenta, pero no administra su estructura.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        // Las sucursales las administra la CUENTA, no personal de comercio.
        return $user instanceof User
            && ! $user->worksForAVendor()
            && $user->tenant instanceof BusinessAccount
            && $user->can(Permission::BranchesManage->value);
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if ($user !== null && $user->tenant instanceof BusinessAccount
            && ! $user->can(Permission::BranchesManage->value)) {
            throw MissingPermissionException::for(Permission::BranchesManage);
        }

        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return BranchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
