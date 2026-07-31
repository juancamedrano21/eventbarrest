<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\StockMovements;

use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Enums\StockMovementType;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Operations\Models\OperatingUnit;
use App\Filament\App\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\App\Support\VendorPanel;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * El libro mayor, de solo lectura: cada entrada y salida con quién y cuándo.
 * No hay crear ni editar — los movimientos nacen de las acciones de
 * Existencias (y pronto, de las ventas del POS).
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 31;

    protected static ?string $modelLabel = 'movimiento';

    protected static ?string $pluralModelLabel = 'movimientos';

    protected static ?string $navigationLabel = 'Movimientos';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(Permission::InventoryManage->value) === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Mismo criterio que Existencias: el libro pertenece al comercio a
     * través de sus unidades. Con comercio activo, solo lo suyo.
     */
    public static function getEloquentQuery(): Builder
    {
        $vendors = app(VendorContext::class);

        return parent::getEloquentQuery()->when(
            $vendors->check(),
            fn (Builder $query): Builder => $query->whereHas(
                'operatingUnit',
                fn (Builder $unit): Builder => $unit->where('vendor_id', $vendors->id()),
            ),
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['operatingUnit', 'inventoryItem', 'user']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                TextColumn::make('operatingUnit.vendor.name')
                    ->label('Comercio')
                    ->placeholder('—')
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('inventoryItem.name')
                    ->label('Insumo')
                    ->searchable(),
                TextColumn::make('operatingUnit.name')
                    ->label('Unidad'),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric(3)
                    ->color(fn (StockMovement $record): string => (float) $record->quantity >= 0 ? 'success' : 'danger'),
                TextColumn::make('unit_cost_cents')
                    ->label('Costo unit.')
                    ->money('DOP', divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label('Registró')
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label('Referencia')
                    ->limit(30)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(StockMovementType::class),
                // Opciones acotadas, no la relación cruda: OperatingUnit no
                // lleva VendorScope y listaría los puestos de otros comercios.
                // (El de insumo sí puede usar la relación: InventoryItem
                // lleva el scope de comercio en el propio modelo.)
                SelectFilter::make('operating_unit_id')
                    ->label('Unidad')
                    ->options(fn (): array => self::unitOptions()),
                SelectFilter::make('inventory_item_id')
                    ->label('Insumo')
                    ->relationship('inventoryItem', 'name'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('El libro está vacío')
            ->emptyStateDescription('Cada compra, ajuste, merma o traslado quedará registrado aquí, con quién y cuándo.');
    }

    /**
     * Con comercio activo, solo sus unidades — el mismo criterio que las
     * acciones de Existencias.
     *
     * @return array<int|string, string>
     */
    private static function unitOptions(): array
    {
        $vendors = app(VendorContext::class);

        return OperatingUnit::query()
            ->when($vendors->check(), fn ($query) => $query->where('vendor_id', $vendors->id()))
            ->pluck('name', 'id')
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
