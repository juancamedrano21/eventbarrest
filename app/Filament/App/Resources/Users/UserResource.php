<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users;

use App\Domains\Identity\Enums\Permission;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Filament\App\Resources\Users\Pages\ListUsers;
use App\Filament\App\Resources\Users\Schemas\UserForm;
use App\Filament\App\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'equipo';

    protected static ?string $navigationLabel = 'Equipo';

    protected static ?int $navigationSort = 90;

    /**
     * User no lleva BelongsToTenant (el login ocurre sin contexto de tenant),
     * así que el aislamiento de esta pantalla se declara aquí, explícitamente:
     * solo el equipo del negocio del usuario autenticado, y nunca staff de
     * plataforma.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::auth()->user()?->tenant_id)
            ->where('is_platform_admin', false);
    }

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(Permission::UsersManage->value) === true;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
