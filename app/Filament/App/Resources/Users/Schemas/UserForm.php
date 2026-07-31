<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Schemas;

use App\Domains\EventManagement\Models\OrganizerAccount;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Identity\Enums\Role as RoleEnum;
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
                    ->options(fn (mixed $get): array => filled($get('vendor_id'))
                        ? RoleEnum::options(RoleEnum::forVendorStaff())
                        : RoleEnum::options(RoleEnum::forAccountStaff()))
                    ->required()
                    ->live()
                    // El estado puede llegar como enum (default) o como string
                    // (valor del select ya renderizado).
                    ->helperText(fn (mixed $state): ?string => match (true) {
                        $state instanceof RoleEnum => $state->description(),
                        is_string($state) => RoleEnum::tryFrom($state)?->description(),
                        default => null,
                    }),
            ]);
    }
}
