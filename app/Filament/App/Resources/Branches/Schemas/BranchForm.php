<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Branches\Schemas;

use App\Domains\Business\Models\Branch;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Sucursal Centro')
                    ->required()
                    ->maxLength(255)
                    // El índice único es la defensa real; esto es para que el
                    // usuario vea un error de formulario y no un 500. Hay que
                    // acotar por tenant a mano: Rule::unique va por el query
                    // builder y no pasa por el global scope.
                    ->unique(
                        table: Branch::class,
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('tenant_id', app(TenantContext::class)->id())
                            ->whereNull('event_id'),
                    )
                    ->validationMessages(['unique' => 'Ya existe una sucursal con ese nombre.']),
                Select::make('kind')
                    ->label('Qué despacha')
                    ->options(OperatingUnitKind::class)
                    ->default(OperatingUnitKind::Mixed)
                    ->required()
                    ->live()
                    ->helperText(fn (mixed $state): ?string => match (true) {
                        $state instanceof OperatingUnitKind => $state->description(),
                        is_string($state) => OperatingUnitKind::tryFrom($state)?->description(),
                        default => null,
                    }),
                Select::make('status')
                    ->label('Estado')
                    ->options(OperatingUnitStatus::class)
                    ->default(OperatingUnitStatus::Active)
                    ->required(),
            ]);
    }
}
