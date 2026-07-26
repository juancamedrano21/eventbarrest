<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Domains\Platform\Rules\ValidRnc;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Tipo de cuenta')
                    ->options(TenantType::class)
                    ->default(TenantType::Business)
                    ->required()
                    ->live()
                    // Inmutable tras el alta: de él dependen la estructura
                    // operativa entera y todo lo vendido bajo ella.
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (mixed $state): ?string => match (true) {
                        $state instanceof TenantType => $state->description(),
                        is_string($state) => TenantType::tryFrom($state)?->description(),
                        default => null,
                    }),
                TextInput::make('name')
                    ->label('Nombre de la cuenta')
                    ->required()
                    ->maxLength(255),
                TextInput::make('rnc')
                    ->label('RNC / Cédula')
                    ->helperText('9 dígitos (RNC) u 11 (cédula). Opcional hasta que emita comprobantes fiscales.')
                    ->rule(new ValidRnc)
                    // La unicidad se comprueba sobre el valor normalizado: la
                    // columna guarda solo dígitos, así que ->unique() compararía
                    // "1-31-24680-9" contra "131246809" y nunca encontraría el
                    // duplicado, dejando que reviente el índice de la base.
                    ->rule(static function (?Tenant $record): Closure {
                        return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (! is_string($value) || blank($value)) {
                                return;
                            }

                            $exists = Tenant::query()
                                ->where('rnc', ValidRnc::normalize($value))
                                ->whereKeyNot($record?->getKey())
                                ->exists();

                            if ($exists) {
                                $fail('Este RNC ya está registrado en otro negocio.');
                            }
                        };
                    })
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? ValidRnc::normalize($state) : null)
                    ->maxLength(20),
                Select::make('status')
                    ->label('Estado')
                    ->options(TenantStatus::class)
                    ->default(TenantStatus::Trial)
                    ->required(),
            ]);
    }
}
