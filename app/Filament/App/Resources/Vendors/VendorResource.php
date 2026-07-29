<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Rules\ValidRnc;
use App\Domains\Tenancy\TenantContext;
use App\Filament\App\Resources\Vendors\Pages\ListVendors;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Los negocios participantes de una cuenta de organizador. Se dan de alta
 * una vez y luego se invitan a los eventos que correspondan: cada uno lleva
 * su catálogo, su inventario y sus ventas por separado.
 */
class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'negocio';

    protected static ?string $pluralModelLabel = 'negocios';

    protected static ?string $navigationLabel = 'Negocios';

    protected static ?int $navigationSort = 11;

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return $user?->tenant instanceof OrganizerAccount
            && $user->can(Permission::EventsManage->value);
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre del negocio')
                ->placeholder('Bar Manolo')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: Vendor::class,
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule
                        ->where('tenant_id', app(TenantContext::class)->id()),
                )
                ->validationMessages(['unique' => 'Ya existe un negocio con ese nombre.']),
            TextInput::make('rnc')
                ->label('RNC / Cédula')
                ->helperText('Suyo, no del organizador: cada negocio factura lo que vende.')
                ->rule(new ValidRnc)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? ValidRnc::normalize($state) : null)
                ->maxLength(20),
            TextInput::make('contact_name')
                ->label('Persona de contacto')
                ->maxLength(255),
            TextInput::make('contact_phone')
                ->label('Teléfono')
                ->tel()
                ->maxLength(30),
            Select::make('status')
                ->label('Estado')
                ->options(VendorStatus::class)
                ->default(VendorStatus::Active)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Negocio')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->label('Contacto')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('contact_phone')
                    ->label('Teléfono')
                    ->placeholder('—'),
                TextColumn::make('events_count')
                    ->label('Eventos')
                    ->counts('events')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(VendorStatus::class),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo negocio'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Aún no hay negocios')
            ->emptyStateDescription('Da de alta los bares y restaurantes que participan en tus eventos.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
        ];
    }
}
