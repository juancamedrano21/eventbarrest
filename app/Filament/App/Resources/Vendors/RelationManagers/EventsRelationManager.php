<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors\RelationManagers;

use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * En qué eventos participa el comercio, con su comisión por evento. Desde
 * aquí también se le invita a uno nuevo.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Eventos en los que participa';

    protected static ?string $modelLabel = 'participación';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Filament::auth()->user()?->can(Permission::EventsManage->value) === true;
    }

    /** En el perfil (página de vista) este panel SÍ opera: no es lectura. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Evento')->searchable(),
                TextColumn::make('starts_at')->label('Inicio')->dateTime('d M Y, H:i'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('pivot.commission_bps')
                    ->label('Comisión')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).' %'),
            ])
            ->headerActions([
                Action::make('invitar')
                    ->label('Invitar a un evento')
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('event_id')
                            ->label('Evento')
                            ->options(function (): array {
                                /** @var Vendor $vendor */
                                $vendor = $this->getOwnerRecord();

                                return Event::query()
                                    ->whereNotIn('id', $vendor->events()->select('events.id'))
                                    ->orderBy('starts_at')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->required(),
                        TextInput::make('commission')
                            ->label('Comisión del organizador (%)')
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)
                            ->default(0)
                            ->helperText('Sobre las ventas del comercio en este evento.'),
                    ])
                    ->action(function (array $data): void {
                        /** @var Vendor $vendor */
                        $vendor = $this->getOwnerRecord();

                        app(InviteVendorToEvent::class)(
                            Event::query()->findOrFail((int) $data['event_id']),
                            $vendor,
                            (int) round(((float) ($data['commission'] ?? 0)) * 100),
                        );
                    }),
            ])
            ->emptyStateHeading('Aún no participa en ningún evento')
            ->emptyStateDescription('Invítalo: ahí podrás asignarle sus puestos de venta.');
    }
}
