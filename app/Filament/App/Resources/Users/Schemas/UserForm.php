<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Users\Schemas;

use App\Domains\Identity\Enums\Role as RoleEnum;
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
                Select::make('role')
                    ->label('Rol')
                    ->options(RoleEnum::class)
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
