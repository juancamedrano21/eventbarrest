<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Events\RelationManagers;

use App\Domains\EventManagement\Actions\InviteVendorToEvent;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué negocios participan en este evento y con qué comisión. Es el paso que
 * conecta los dos lados: los negocios existen en la cuenta, los eventos
 * también, y aquí se decide quién va a cuál.
 */
class VendorsRelationManager extends RelationManager
{
    protected static string $relationship = 'vendors';

    protected static ?string $title = 'Negocios participantes';

    protected static ?string $modelLabel = 'negocio';

    /**
     * Los relation managers no heredan el gate del recurso padre: sin esto,
     * cualquiera con acceso al evento podía operar aquí.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Filament::auth()->user()?->can(Permission::EventsManage->value) === true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('commission_bps')
                ->label('Comisión')
                ->suffix('%')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->default(0)
                ->required()
                ->formatStateUsing(fn (?int $state): float => ($state ?? 0) / 100)
                ->dehydrateStateUsing(fn (float|int|string|null $state): int => (int) round(((float) $state) * 100))
                ->helperText('Lo que retiene el organizador de lo que venda este negocio en el evento.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Negocio')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->label('Contacto')
                    ->placeholder('—'),
                TextColumn::make('pivot.commission_bps')
                    ->label('Comisión')
                    ->formatStateUsing(fn (?int $state): string => number_format(($state ?? 0) / 100, 2).' %')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Invitar negocio')
                    ->modalHeading('Invitar un negocio a este evento')
                    ->recordSelect(fn (Select $select): Select => $select
                        ->label('Negocio')
                        ->options(fn (): array => Vendor::query()
                            ->where('status', VendorStatus::Active)
                            ->whereDoesntHave('events', fn ($query) => $query->whereKey($this->getOwnerRecord()->getKey()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required())
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('commission_bps')
                            ->label('Comisión')
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->default(0)
                            ->required(),
                    ])
                    ->using(function (array $data): void {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();

                        app(InviteVendorToEvent::class)(
                            $event,
                            Vendor::query()->findOrFail($data['recordId']),
                            (int) round(((float) $data['commission_bps']) * 100),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('comision')
                    ->label('Comisión')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        TextInput::make('commission_bps')
                            ->label('Comisión')
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->required(),
                    ])
                    ->fillForm(fn (Vendor $record): array => [
                        'commission_bps' => ((int) ($record->pivot->commission_bps ?? 0)) / 100,
                    ])
                    ->action(function (Vendor $record, array $data): void {
                        /** @var Event $event */
                        $event = $this->getOwnerRecord();

                        app(InviteVendorToEvent::class)(
                            $event,
                            $record,
                            (int) round(((float) $data['commission_bps']) * 100),
                        );
                    }),
                DetachAction::make()
                    ->label('Quitar del evento')
                    ->modalDescription('El negocio deja de participar. Sus puntos de venta de este evento dejan de operar.'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Ningún negocio invitado todavía')
            ->emptyStateDescription('Invita los bares y restaurantes que venderán en este evento.');
    }
}
