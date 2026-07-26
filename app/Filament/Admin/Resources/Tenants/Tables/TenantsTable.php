<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Domains\Platform\Actions\ActivateTenant;
use App\Domains\Platform\Actions\SuspendTenant;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Negocio')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rnc')
                    ->label('RNC')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(TenantStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('suspender')
                    ->label('Suspender')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Suspended)
                    ->requiresConfirmation()
                    ->modalHeading('Suspender negocio')
                    ->modalDescription('Sus usuarios y terminales perderán acceso hasta que se reactive. No se borra ningún dato.')
                    ->action(fn (Tenant $record) => app(SuspendTenant::class)($record)),
                Action::make('activar')
                    ->label('Activar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Active)
                    ->requiresConfirmation()
                    ->action(fn (Tenant $record) => app(ActivateTenant::class)($record)),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
