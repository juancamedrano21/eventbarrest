<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\StockLevels;

use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Actions\AdjustStock;
use App\Domains\Inventory\Actions\RegisterPurchase;
use App\Domains\Inventory\Actions\RegisterWaste;
use App\Domains\Inventory\Actions\TransferStock;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Operations\Models\OperatingUnit;
use App\Filament\App\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\App\Support\VendorPanel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Las existencias por unidad operativa. La tabla es una proyección del
 * libro de movimientos: aquí solo se edita el umbral de alerta — el stock
 * cambia únicamente con compras, ajustes, mermas y traslados.
 */
class StockLevelResource extends Resource
{
    protected static ?string $model = StockLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'existencia';

    protected static ?string $pluralModelLabel = 'existencias';

    protected static ?string $navigationLabel = 'Existencias';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(Permission::InventoryManage->value) === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && VendorPanel::writesAllowed();
    }

    /**
     * El stock no lleva vendor_id propio: pertenece al comercio a través de
     * su unidad (todo puesto de evento exige comercio). Con comercio activo,
     * solo sus unidades.
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('alert_threshold')
                ->label('Umbral de alerta')
                ->numeric()
                ->minValue(0)
                ->step(0.001)
                ->helperText('Cuando las existencias caigan a este nivel o menos, la fila se marca en rojo.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['operatingUnit', 'inventoryItem']))
            ->columns([
                TextColumn::make('operatingUnit.vendor.name')
                    ->label('Comercio')
                    ->placeholder('—')
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                TextColumn::make('operatingUnit.name')
                    ->label('Unidad')
                    ->sortable(),
                TextColumn::make('inventoryItem.name')
                    ->label('Insumo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Existencia')
                    ->numeric(3)
                    ->suffix(fn (StockLevel $record): string => ' '.($record->inventoryItem?->base_unit->short() ?? ''))
                    ->sortable(),
                TextColumn::make('alert_threshold')
                    ->label('Umbral')
                    ->numeric(3)
                    ->placeholder('—'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->state(fn (StockLevel $record): string => $record->isLow() ? 'Bajo mínimo' : 'OK')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'OK' ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('operating_unit_id')
                    ->label('Unidad')
                    ->relationship('operatingUnit', 'name'),
            ])
            ->headerActions([
                Action::make('compra')
                    ->label('Registrar compra')
                    ->icon(Heroicon::OutlinedTruck)
                    ->color('success')
                    ->visible(fn (): bool => VendorPanel::writesAllowed())
                    ->schema([
                        ...self::unitAndItemSelects(),
                        TextInput::make('quantity')
                            ->label('Cantidad recibida')
                            ->numeric()->minValue(0.001)->step(0.001)->required(),
                        TextInput::make('unit_cost')
                            ->label('Costo unitario pagado')
                            ->prefix('RD$')->numeric()->minValue(0)->step(0.01)->required()
                            ->helperText('Por unidad base. Recalcula el costo promedio del insumo.'),
                        TextInput::make('reference')
                            ->label('Referencia')
                            ->placeholder('Factura o proveedor')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        app(RegisterPurchase::class)(
                            OperatingUnit::query()->findOrFail($data['operating_unit_id']),
                            InventoryItem::query()->findOrFail($data['inventory_item_id']),
                            (float) $data['quantity'],
                            (int) round(((float) $data['unit_cost']) * 100),
                            $data['reference'] ?? null,
                        );
                        Notification::make()->success()->title('Compra registrada')->send();
                    }),
                Action::make('ajuste')
                    ->label('Ajuste')
                    ->icon(Heroicon::OutlinedScale)
                    ->color('warning')
                    ->visible(fn (): bool => VendorPanel::writesAllowed()
                        && Filament::auth()->user()?->can(Permission::InventoryAdjust->value) === true)
                    ->schema([
                        ...self::unitAndItemSelects(),
                        TextInput::make('quantity')
                            ->label('Diferencia (con signo)')
                            ->numeric()->step(0.001)->required()
                            ->helperText('Lo contado menos lo que dice el sistema: +2 si sobró, -3 si faltó.'),
                        TextInput::make('reference')
                            ->label('Motivo')
                            ->placeholder('Conteo físico semanal')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        app(AdjustStock::class)(
                            OperatingUnit::query()->findOrFail($data['operating_unit_id']),
                            InventoryItem::query()->findOrFail($data['inventory_item_id']),
                            (float) $data['quantity'],
                            $data['reference'] ?? null,
                        );
                        Notification::make()->success()->title('Ajuste registrado')->send();
                    }),
                Action::make('merma')
                    ->label('Merma')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (): bool => VendorPanel::writesAllowed()
                        && Filament::auth()->user()?->can(Permission::InventoryAdjust->value) === true)
                    ->schema([
                        ...self::unitAndItemSelects(),
                        TextInput::make('quantity')
                            ->label('Cantidad perdida')
                            ->numeric()->minValue(0.001)->step(0.001)->required(),
                        TextInput::make('reference')
                            ->label('Motivo')
                            ->placeholder('Botella rota')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        app(RegisterWaste::class)(
                            OperatingUnit::query()->findOrFail($data['operating_unit_id']),
                            InventoryItem::query()->findOrFail($data['inventory_item_id']),
                            (float) $data['quantity'],
                            $data['reference'] ?? null,
                        );
                        Notification::make()->success()->title('Merma registrada')->send();
                    }),
                Action::make('traslado')
                    ->label('Traslado')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (): bool => VendorPanel::writesAllowed()
                        && Filament::auth()->user()?->can(Permission::InventoryTransfer->value) === true)
                    ->schema([
                        Select::make('from_unit_id')
                            ->label('Desde')
                            ->options(fn (): array => self::unitOptions())
                            ->required()
                            ->different('to_unit_id'),
                        Select::make('to_unit_id')
                            ->label('Hacia')
                            ->options(fn (): array => self::unitOptions())
                            ->required()
                            ->different('from_unit_id'),
                        Select::make('inventory_item_id')
                            ->label('Insumo')
                            ->options(fn (): array => InventoryItem::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()->minValue(0.001)->step(0.001)->required(),
                    ])
                    ->action(function (array $data): void {
                        app(TransferStock::class)(
                            OperatingUnit::query()->findOrFail($data['from_unit_id']),
                            OperatingUnit::query()->findOrFail($data['to_unit_id']),
                            InventoryItem::query()->findOrFail($data['inventory_item_id']),
                            (float) $data['quantity'],
                        );
                        Notification::make()->success()->title('Traslado registrado')->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Umbral'),
            ])
            ->defaultSort('inventory_item_id')
            ->emptyStateHeading('Sin existencias todavía')
            ->emptyStateDescription('Registra la primera compra para empezar a mover inventario.');
    }

    /**
     * @return array<int, Select>
     */
    private static function unitAndItemSelects(): array
    {
        return [
            Select::make('operating_unit_id')
                ->label('Unidad')
                ->options(fn (): array => self::unitOptions())
                ->required(),
            Select::make('inventory_item_id')
                ->label('Insumo')
                ->options(fn (): array => InventoryItem::query()->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
        ];
    }

    /**
     * Con comercio activo, solo sus unidades: su gente no ve — ni elige —
     * los puestos de otros comercios. (Los insumos ya vienen filtrados por
     * el scope de vendor del propio modelo.)
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
            'index' => ListStockLevels::route('/'),
        ];
    }
}
