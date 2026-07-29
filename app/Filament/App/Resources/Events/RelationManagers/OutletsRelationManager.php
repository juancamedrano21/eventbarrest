<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\RelationManagers;

use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Los puntos de venta del evento: las barras y cocinas que cada negocio
 * participante monta. Un punto pertenece siempre a un negocio invitado —
 * el organizador relaciona, el negocio opera.
 */
class OutletsRelationManager extends RelationManager
{
    protected static string $relationship = 'outlets';

    protected static ?string $title = 'Puntos de venta';

    protected static ?string $modelLabel = 'punto de venta';

    /**
     * Los relation managers no heredan el gate del recurso padre: sin esto,
     * cualquiera con acceso al evento podía operar aquí.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Filament::auth()->user()?->can(Permission::EventOutletsManage->value) === true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('Negocio')
                    ->options(fn (): array => $this->invitedVendors()->pluck('name', 'id')->all())
                    ->required()
                    ->searchable()
                    ->helperText('Solo aparecen los negocios invitados a este evento.')
                    // El negocio define de quién es el stock y las ventas del
                    // punto: se elige al crear y no cambia.
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Barra principal')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: EventOutlet::class,
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, mixed $get): Unique {
                            /** @var Event $event */
                            $event = $this->getOwnerRecord();

                            return $rule
                                ->where('tenant_id', $event->tenant_id)
                                ->where('event_id', $event->getKey())
                                ->where('vendor_id', $get('vendor_id'));
                        },
                    )
                    ->validationMessages(['unique' => 'Ese negocio ya tiene un punto de venta con ese nombre en este evento.']),
                Select::make('kind')
                    ->label('Qué despacha')
                    ->options(OperatingUnitKind::class)
                    ->default(OperatingUnitKind::Bar)
                    ->required()
                    ->live()
                    ->helperText(fn (mixed $state): ?string => match (true) {
                        $state instanceof OperatingUnitKind => $state->description(),
                        is_string($state) => OperatingUnitKind::tryFrom($state)?->description(),
                        default => null,
                    }),
                Select::make('status')
                    ->label('Estado')
                    ->options(OperatingUnitStatus::class)
                    ->default(OperatingUnitStatus::Active)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('vendor.name')
                    ->label('Negocio')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Punto de venta')
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Despacha')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('vendor_id')
                    ->label('Negocio')
                    ->options(fn (): array => $this->invitedVendors()->pluck('name', 'id')->all()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir punto de venta')
                    ->using(function (array $data): EventOutlet {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();

                        $vendor = Vendor::query()->findOrFail($data['vendor_id']);

                        return app(CreateEventOutlet::class)(
                            $event,
                            $vendor,
                            $data['name'],
                            OperatingUnitKind::coerce($data['kind']),
                            OperatingUnitStatus::coerce($data['status']),
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Este evento aún no tiene puntos de venta')
            ->emptyStateDescription('Invita negocios al evento y añade sus barras y cocinas.');
    }

    /**
     * @return Collection<int, Vendor>
     */
    private function invitedVendors(): Collection
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return $event->vendors()->orderBy('name')->get();
    }
}
