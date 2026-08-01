<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Vendors\RelationManagers;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\RoleTemplate;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

/**
 * El equipo del comercio, administrado desde su perfil: el dueño del evento
 * crea aquí a la gente del comercio ya adscrita — sin pasar por el selector
 * de la pantalla general de Equipo.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Equipo del comercio';

    protected static ?string $modelLabel = 'usuario';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && ! $user->worksForAVendor()
            && $user->can(Permission::UsersManage->value);
    }

    /** En el perfil (página de vista) este panel SÍ opera: no es lectura. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            TextInput::make('username')
                ->label('Usuario del POS')
                ->maxLength(30)
                ->rule('regex:/^[a-z0-9._-]+$/i')
                ->unique(table: User::class, ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null)
                ->helperText('Lo que teclea en el terminal para entrar al POS.'),
            TextInput::make('email')
                ->label('Correo')
                ->email()
                ->required()
                ->unique(table: User::class, ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('password')
                ->label('Contraseña')
                ->password()
                ->revealable()
                ->required()
                ->rule(Password::default()),
            Select::make('role')
                ->label('Rol')
                ->options(fn (): array => RoleTemplate::optionsForVendorStaff())
                ->required()
                ->helperText(fn (mixed $state): ?string => is_string($state) && $state !== ''
                    ? RoleTemplate::descriptionFor($state)
                    : null),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('username')->label('Usuario POS')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('email')->label('Correo'),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => once(
                        fn (): array => RoleTemplate::query()->pluck('label', 'name')->all()
                    )[$state] ?? $state),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nuevo usuario del comercio')
                    ->using(function (array $data): User {
                        /** @var Vendor $vendor */
                        $vendor = $this->getOwnerRecord();

                        return app(CreateTenantUser::class)(
                            $vendor->tenant,
                            $data['name'],
                            $data['email'],
                            $data['password'],
                            (string) $data['role'],
                            $vendor,
                            username: $data['username'] ?? null,
                        );
                    }),
            ])
            ->recordActions([
                Action::make('cambiarRol')
                    ->label('Cambiar rol')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Select::make('role')
                            ->label('Rol')
                            ->options(fn (): array => RoleTemplate::optionsForVendorStaff())
                            ->required(),
                    ])
                    ->action(fn (User $record, array $data) => app(AssignTenantRole::class)(
                        $record,
                        (string) $data['role'],
                    )),
            ])
            ->emptyStateHeading('Este comercio aún no tiene equipo')
            ->emptyStateDescription('Crea su encargado: él montará el catálogo y sus cajeros venderán en el POS.');
    }
}
