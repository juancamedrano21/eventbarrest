<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\RelationManagers;

use App\Domains\EventManagement\Models\Event;
use App\Domains\Operations\Actions\CreateEventOutlet;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Models\OperatingUnit;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Los puntos de venta de un evento: las barras y cocinas que operan dentro.
 *
 * Aquí es donde entra un negocio que quiera participar en el festival: se crea
 * como punto del evento, con su propio catálogo, inventario y personal. Aunque
 * lleve el mismo nombre que un negocio cliente, no comparte nada con él.
 */
class OutletsRelationManager extends RelationManager
{
    protected static string $relationship = 'operatingUnits';

    protected static ?string $title = 'Puntos de venta';

    protected static ?string $modelLabel = 'punto de venta';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Barra principal')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: OperatingUnit::class,
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule): Unique {
                            /** @var Event $event */
                            $event = $this->getOwnerRecord();

                            return $rule
                                ->where('tenant_id', $event->tenant_id)
                                ->where('event_id', $event->getKey());
                        },
                    )
                    ->validationMessages(['unique' => 'Este evento ya tiene un punto de venta con ese nombre.']),
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
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir punto de venta')
                    ->using(function (array $data): OperatingUnit {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();

                        return app(CreateEventOutlet::class)(
                            $event,
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
            ->emptyStateDescription('Añade sus barras y cocinas para poder vender.');
    }
}
