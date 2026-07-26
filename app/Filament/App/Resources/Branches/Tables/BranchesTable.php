<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Branches\Tables;

use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Sucursal')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Despacha')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Qué despacha')
                    ->options(OperatingUnitKind::class),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(OperatingUnitStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Aún no hay sucursales')
            ->emptyStateDescription('Crea la primera para empezar a vender.');
    }
}
