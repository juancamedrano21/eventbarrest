<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\Tables;

use App\Domains\EventManagement\Enums\EventStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Evento')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('venue')
                    ->label('Lugar')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('starts_at')
                    ->label('Comienza')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                TextColumn::make('outlets_count')
                    ->label('Puntos de venta')
                    ->counts('outlets')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EventStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('Abrir'),
            ])
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading('Aún no hay eventos')
            ->emptyStateDescription('Crea uno y añade dentro sus barras y cocinas.');
    }
}
