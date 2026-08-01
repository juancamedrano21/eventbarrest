<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Schemas;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Models\RoleTemplate;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('username')
                    ->label('Usuario del POS')
                    ->placeholder('caro')
                    ->maxLength(30)
                    ->rule('regex:/^[a-z0-9._-]+$/i')
                    ->unique(table: User::class, ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null)
                    ->validationMessages([
                        'unique' => 'Ese usuario ya está tomado en la plataforma.',
                        'regex' => 'Solo letras, números, punto, guion y guion bajo.',
                    ])
                    ->helperText('Lo que teclea en el terminal para entrar al POS. Vacío si no vende.'),
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
                    ->rule(Password::default())
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Déjala vacía para no cambiarla.'
                        : null),
                // Solo en cuentas de organizador: a qué comercio pertenece.
                // Se decide al crear y no cambia — la visibilidad de datos
                // del usuario depende de esto.
                Select::make('vendor_id')
                    ->label('Comercio')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => app(TenantContext::class)->current() instanceof OrganizerAccount)
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->placeholder('Equipo de la cuenta')
                    ->helperText('Vacío: pertenece al equipo del organizador y ve el consolidado. '
                        .'Con comercio: opera únicamente dentro de ese comercio.'),
                Select::make('role')
                    ->label('Rol')
                    // Del catálogo vivo de la plataforma (plantillas), no del
                    // enum: incluye los roles creados por el superadmin.
                    ->options(fn (mixed $get): array => filled($get('vendor_id'))
                        ? RoleTemplate::optionsForVendorStaff()
                        : RoleTemplate::optionsForAccountStaff())
                    ->required()
                    ->live()
                    ->helperText(fn (mixed $state): ?string => is_string($state) && $state !== ''
                        ? RoleTemplate::descriptionFor($state)
                        : null),
            ]);
    }
}
