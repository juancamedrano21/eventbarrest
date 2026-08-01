<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleTemplates;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Enums\RoleKind;
use App\Domains\Identity\Exceptions\RoleTemplateException;
use App\Domains\Identity\Models\RoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\Pages\CreateRoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\Pages\EditRoleTemplate;
use App\Filament\Admin\Resources\RoleTemplates\Pages\ListRoleTemplates;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El sistema de roles de la plataforma, operado por el superadmin: ajustar
 * los límites (permisos) de cada rol y crear roles nuevos. Cada cambio se
 * propaga a todas las cuentas. El catálogo de PERMISOS es fijo en código:
 * un permiso sin código que lo compruebe no protege nada.
 */
class RoleTemplateResource extends Resource
{
    protected static ?string $model = RoleTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'rol';

    protected static ?string $pluralModelLabel = 'roles y permisos';

    protected static ?string $navigationLabel = 'Roles y permisos';

    protected static ?int $navigationSort = 20;

    /**
     * Las plantillas de sistema se siembran antes de listar: en una
     * plataforma recién instalada la pantalla nace ya con los 7 roles.
     */
    public static function getEloquentQuery(): Builder
    {
        RoleTemplate::ensureSystemTemplates();

        return parent::getEloquentQuery();
    }

    /** El rol de dueño es la raíz de cada cuenta: ni editar ni eliminar. */
    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return $record instanceof RoleTemplate && $record->name === RoleEnum::Owner->value
            ? Response::deny('El rol de dueño no se edita.')
            : parent::getEditAuthorizationResponse($record);
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return $record instanceof RoleTemplate && $record->is_system
            ? Response::deny('Los roles de sistema se ajustan, nunca se eliminan.')
            : parent::getDeleteAuthorizationResponse($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->label('Nombre')
                ->placeholder('Supervisor de barra')
                ->required()
                ->maxLength(100)
                ->unique(table: RoleTemplate::class, ignoreRecord: true)
                ->validationMessages(['unique' => 'Ya existe un rol con ese nombre.'])
                // El identificador se deriva del nombre: su colisión (y el
                // caso sin letras) se valida AQUÍ para que salga como error
                // de campo y no como excepción del guard del modelo.
                ->rules(fn (string $operation): array => $operation !== 'create' ? [] : [
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        $name = Str::slug((string) $value, '_');

                        if ($name === '') {
                            $fail('El nombre debe contener letras o números.');

                            return;
                        }

                        if (in_array($name, RoleEnum::values(), true)
                            || RoleTemplate::query()->where('name', $name)->exists()) {
                            $fail("Ya existe un rol cuyo identificador sería [{$name}]: elige otro nombre.");
                        }
                    },
                ])
                ->helperText(fn (string $operation): ?string => $operation === 'create'
                    ? 'El identificador interno se genera de este nombre y ya no cambia.'
                    : null),
            Textarea::make('description')
                ->label('Descripción')
                ->rows(2)
                ->maxLength(255)
                ->helperText('Se muestra al asignar el rol, para elegir bien.'),
            Select::make('kind')
                ->label('Se asigna a')
                ->options(RoleKind::class)
                ->default(RoleKind::Account)
                ->required()
                ->live()
                // El alcance es identidad del rol: se decide al crear. Si
                // cambiara, usuarios ya asignados quedarían del lado
                // equivocado de la frontera cuenta/comercio.
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->helperText('La frontera entre cuenta y comercio: un rol de cuenta nunca baja a un comercio, ni al revés.'),
            CheckboxList::make('permissions')
                ->label('Permisos (los límites del rol)')
                // Filtrados por alcance: los permisos de administración de
                // cuenta ni se ofrecen en roles asignables a comercios (el
                // guard del modelo los rechaza de todas formas).
                ->options(fn (mixed $get): array => Permission::labeledOptionsForKind(self::kindFrom($get('kind'))))
                ->descriptions(Permission::descriptions())
                ->columns(2)
                ->required()
                ->bulkToggleable()
                ->validationMessages(['required' => 'Un rol sin permisos no deja hacer nada: marca al menos uno.'])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Rol')
                    ->searchable()
                    ->sortable()
                    ->description(fn (RoleTemplate $record): ?string => $record->description),
                TextColumn::make('name')
                    ->label('Identificador')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('kind')
                    ->label('Se asigna a')
                    ->badge(),
                TextColumn::make('permisos')
                    ->label('Permisos')
                    ->state(fn (RoleTemplate $record): int => count($record->permissions))
                    ->alignCenter(),
                TextColumn::make('usuarios')
                    ->label('Usuarios')
                    // once(): un solo agregado por petición, no uno por fila.
                    ->state(fn (RoleTemplate $record): int => once(
                        fn (): array => DB::table('model_has_roles')
                            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                            ->groupBy('roles.name')
                            ->selectRaw('roles.name as name, count(distinct model_has_roles.model_id) as total')
                            ->pluck('total', 'name')
                            ->all()
                    )[$record->name] ?? 0)
                    ->alignCenter(),
                IconColumn::make('is_system')
                    ->label('Sistema')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                self::configureDeleteAction(DeleteAction::make()),
            ])
            ->defaultSort('label')
            ->emptyStateHeading('Sin roles todavía')
            ->emptyStateDescription('Los roles de sistema se siembran solos al abrir esta pantalla.');
    }

    /**
     * El borrado corre en transacción (guard + limpieza spatie, o todo o
     * nada) y los guards del modelo salen como notificación, no como 500 —
     * incluso si el estado cambió entre abrir el modal y confirmar.
     */
    public static function configureDeleteAction(DeleteAction $action): DeleteAction
    {
        return $action->using(function (RoleTemplate $record, DeleteAction $action): bool {
            try {
                return (bool) DB::transaction(fn (): bool => (bool) $record->delete());
            } catch (RoleTemplateException $e) {
                $action->failureNotification(
                    fn (Notification $notification): Notification => $notification
                        ->danger()
                        ->title('No se puede eliminar')
                        ->body($e->getMessage()),
                );

                return false;
            }
        });
    }

    private static function kindFrom(mixed $state): RoleKind
    {
        return match (true) {
            $state instanceof RoleKind => $state,
            is_string($state) && RoleKind::tryFrom($state) !== null => RoleKind::from($state),
            default => RoleKind::Account,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoleTemplates::route('/'),
            'create' => CreateRoleTemplate::route('/create'),
            'edit' => EditRoleTemplate::route('/{record}/edit'),
        ];
    }
}
