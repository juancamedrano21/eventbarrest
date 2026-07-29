<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events;

use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Exceptions\MissingPermissionException;
use App\Filament\App\Resources\Events\Pages\CreateEvent;
use App\Filament\App\Resources\Events\Pages\EditEvent;
use App\Filament\App\Resources\Events\Pages\ListEvents;
use App\Filament\App\Resources\Events\RelationManagers\OutletsRelationManager;
use App\Filament\App\Resources\Events\Schemas\EventForm;
use App\Filament\App\Resources\Events\Tables\EventsTable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Eventos: el mundo de las cuentas de organizador. Cada evento es cerrado por
 * dentro y no comparte nada con ningún negocio de la plataforma.
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'evento';

    protected static ?string $pluralModelLabel = 'eventos';

    protected static ?string $navigationLabel = 'Eventos';

    protected static ?int $navigationSort = 10;

    /**
     * Mundo correcto (cuenta de organizador) más el permiso de la matriz.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return $user?->tenant instanceof OrganizerAccount
            && $user->can(Permission::EventsManage->value);
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    /**
     * Un 403 seco obliga a depurar a ciegas. Si el usuario está en el mundo
     * correcto pero le falta el permiso, se lo decimos con nombre.
     */
    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if ($user !== null && $user->tenant instanceof OrganizerAccount
            && ! $user->can(Permission::EventsManage->value)) {
            throw MissingPermissionException::for(Permission::EventsManage);
        }

        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OutletsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
