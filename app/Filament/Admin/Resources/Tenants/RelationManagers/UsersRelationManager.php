<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Domains\Identity\Actions\AssignTenantRole;
use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Platform\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * Alta del equipo de un negocio desde el panel de plataforma. Es el paso que
 * cierra el onboarding: sin al menos un dueño, nadie puede entrar en /app.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Equipo';

    protected static ?string $modelLabel = 'usuario';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
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
                    ->rule(Password::default())
                    ->dehydrated(),
                Select::make('role')
                    ->label('Rol')
                    // Desde /admin solo se da de alta equipo de cuenta; el
                    // personal de los comercios lo asigna el organizador
                    // desde su propio panel.
                    ->options(fn (): array => RoleTemplate::optionsForAccountStaff())
                    ->default(RoleEnum::Owner->value)
                    ->required()
                    ->helperText('El primer usuario de un negocio debe ser Dueño.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => once(
                        fn (): array => RoleTemplate::query()->pluck('label', 'name')->all()
                    )[$state] ?? $state),
                TextColumn::make('created_at')
                    ->label('Alta')
                    ->date()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir usuario')
                    ->modalHeading('Añadir usuario al negocio')
                    ->using(function (array $data): User {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        return app(CreateTenantUser::class)(
                            $tenant,
                            $data['name'],
                            $data['email'],
                            $data['password'],
                            (string) $data['role'],
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
                            ->options(fn (User $record): array => $record->worksForAVendor()
                                ? RoleTemplate::optionsForVendorStaff()
                                : RoleTemplate::optionsForAccountStaff())
                            ->required(),
                    ])
                    ->action(fn (User $record, array $data) => app(AssignTenantRole::class)(
                        $record,
                        (string) $data['role'],
                    )),
            ]);
    }
}
