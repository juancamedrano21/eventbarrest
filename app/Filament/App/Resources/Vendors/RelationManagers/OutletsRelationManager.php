<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors\RelationManagers;

use App\Domains\EventManagement\Actions\CreateEventOutlet;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventOutlet;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Operations\Enums\OperatingUnitKind;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Los puestos del comercio a través de todos sus eventos. Crear uno aquí
 * pide el evento (entre los que participa) y llega ya adscrito al comercio.
 */
class OutletsRelationManager extends RelationManager
{
    protected static string $relationship = 'operatingUnits';

    protected static ?string $title = 'Puestos de venta';

    protected static ?string $modelLabel = 'puesto';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Filament::auth()->user()?->can(Permission::EventOutletsManage->value) === true;
    }

    /** En el perfil (página de vista) este panel SÍ opera: no es lectura. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('event_id')
                ->label('Evento')
                ->options(function (): array {
                    /** @var Vendor $vendor */
                    $vendor = $this->getOwnerRecord();

                    return $vendor->events()->orderBy('starts_at')->pluck('events.name', 'events.id')->all();
                })
                ->required()
                ->helperText('Solo los eventos en los que este comercio participa.'),
            TextInput::make('name')
                ->label('Nombre del puesto')
                ->placeholder('Barra principal')
                ->required()
                ->maxLength(255),
            Select::make('kind')
                ->label('Despacha')
                ->options(OperatingUnitKind::class)
                ->default(OperatingUnitKind::Bar)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Puesto')->searchable(),
                TextColumn::make('event.name')->label('Evento'),
                TextColumn::make('kind')->label('Despacha')->badge(),
                TextColumn::make('status')->label('Estado')->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nuevo puesto')
                    ->using(function (array $data): EventOutlet {
                        /** @var Vendor $vendor */
                        $vendor = $this->getOwnerRecord();

                        return app(CreateEventOutlet::class)(
                            Event::query()->findOrFail((int) $data['event_id']),
                            $vendor,
                            $data['name'],
                            OperatingUnitKind::coerce($data['kind']),
                        );
                    }),
            ])
            ->emptyStateHeading('Sin puestos todavía')
            ->emptyStateDescription('Invítalo a un evento y asígnale aquí su barra o cocina.');
    }
}
