<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\Schemas;

use App\Domains\EventManagement\Enums\EventStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre del evento')
                    ->placeholder('Festival del Mar 2026')
                    ->required()
                    ->maxLength(255),
                TextInput::make('venue')
                    ->label('Lugar')
                    ->placeholder('Malecón de Santo Domingo')
                    ->maxLength(255),
                DateTimePicker::make('starts_at')
                    ->label('Comienza')
                    ->seconds(false)
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->label('Termina')
                    ->seconds(false)
                    ->required()
                    ->after('starts_at'),
                Select::make('status')
                    ->label('Estado')
                    ->options(EventStatus::class)
                    ->default(EventStatus::Draft)
                    ->required(),
            ]);
    }
}
