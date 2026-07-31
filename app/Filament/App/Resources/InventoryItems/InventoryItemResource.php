<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\InventoryItems;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Inventory\Enums\MeasurementUnit;
use App\Domains\Inventory\Models\InventoryItem;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\App\Support\VendorPanel;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Los insumos: lo que se compra y se consume. El costo por unidad base
 * alimenta el escandallo; el costo promedio ponderado llegará con las
 * compras del dominio Inventory.
 */
class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'insumo';

    protected static ?string $pluralModelLabel = 'insumos';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user()?->can(Permission::CatalogManage->value) === true;
    }

    /**
     * Respuestas de autorización, no canCreate/canEdit: los modales de este
     * recurso consultan estas respuestas y los booleanos derivan de ellas.
     */
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canViewAny() && VendorPanel::writesAllowed()
            ? parent::getCreateAuthorizationResponse()
            : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::canViewAny() && VendorPanel::writesAllowed()
            ? parent::getEditAuthorizationResponse($record)
            : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::canViewAny() && VendorPanel::writesAllowed()
            ? parent::getDeleteAuthorizationResponse($record)
            : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->placeholder('Ron blanco')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: InventoryItem::class,
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->where('vendor_id', app(VendorContext::class)->id()),
                )
                ->validationMessages(['unique' => 'Ya existe un insumo con ese nombre.']),
            Select::make('base_unit')
                ->label('Unidad base')
                ->options(MeasurementUnit::class)
                ->default(MeasurementUnit::Milliliter)
                ->required()
                ->helperText('Las recetas, compras y mermas hablarán siempre en esta unidad.'),
            TextInput::make('cost_cents')
                ->label('Costo por unidad base')
                ->prefix('RD$')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->required()
                ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                ->dehydrateStateUsing(fn (float|int|string|null $state): int => (int) round(((float) $state) * 100))
                ->helperText('Cuánto cuesta un ml, un gramo o una unidad. Ej.: si la botella de 750 ml cuesta RD$600, el ml cuesta RD$0.80.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Insumo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vendor.name')
                    ->label('Comercio')
                    ->placeholder('De la cuenta')
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                TextColumn::make('base_unit')
                    ->label('Unidad')
                    ->badge(),
                TextColumn::make('cost_cents')
                    ->label('Costo por unidad')
                    ->money('DOP', divideBy: 100)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Comercio')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => VendorPanel::consolidatedOrganizerView()),
                SelectFilter::make('base_unit')
                    ->label('Unidad')
                    ->options(MeasurementUnit::class),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo insumo'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Aún no hay insumos')
            ->emptyStateDescription('Ron, limones, azúcar: lo que compras y consumes.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
        ];
    }
}
