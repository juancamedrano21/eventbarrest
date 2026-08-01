<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Tables;

use App\Domains\Identity\Models\RoleTemplate;
use App\Filament\App\Support\VendorPanel;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),
                TextColumn::make('username')
                    ->label('Usuario POS')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('vendor.name')
                    ->label('Comercio')
                    ->placeholder('Equipo de la cuenta')
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge()
                    // once(): un solo pluck por petición, no uno por fila.
                    ->formatStateUsing(fn (string $state): string => once(
                        fn (): array => RoleTemplate::query()->pluck('label', 'name')->all()
                    )[$state] ?? $state),
                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
