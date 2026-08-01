<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FoodTypes;

use App\Domains\Platform\Exceptions\CatalogInUseException;
use App\Domains\Platform\Models\FoodType;
use App\Filament\Admin\Resources\FoodTypes\Pages\ListFoodTypes;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de plataforma: tipos de comida para clasificar comercios. Lo administra
 * el superadmin; todas las cuentas lo consumen.
 */
class FoodTypesResource extends Resource
{
    protected static ?string $model = FoodType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCake;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'tipo de comida';

    protected static ?string $pluralModelLabel = 'tipos de comida';

    protected static ?string $navigationLabel = 'Tipos de comida';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(100)
                ->unique(table: FoodType::class, ignoreRecord: true)
                ->validationMessages(['unique' => 'Ya existe con ese nombre.']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('comercios')
                    ->label('Comercios')
                    ->state(fn (FoodType $record): int => (int) DB::table('vendors')->where('food_type_id', $record->id)->count())
                    ->alignCenter(),
                TextColumn::make('created_at')->label('Creado')->date(),
            ])
            ->headerActions([CreateAction::make()->label('Nuevo')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(function (FoodType $record, DeleteAction $action): bool {
                    // Chequeo explícito (el guard del modelo queda de red).
                    if ($record->vendors()->withoutGlobalScopes()->exists()) {
                        $action->failureNotification(
                            fn (Notification $n): Notification => $n->danger()
                                ->title('No se puede eliminar')
                                ->body(CatalogInUseException::foodType($record->name)->getMessage()),
                        );

                        return false;
                    }

                    return (bool) $record->delete();
                }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFoodTypes::route('/'),
        ];
    }
}
