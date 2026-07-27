<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Domains\Catalog\Enums\ProductType;
use App\Domains\Catalog\Models\Product;
use App\Domains\Tenancy\TenantContext;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Mojito')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: Product::class,
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('tenant_id', app(TenantContext::class)->id()),
                    )
                    ->validationMessages(['unique' => 'Ya existe un producto con ese nombre.']),
                Select::make('category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                TextInput::make('price_cents')
                    ->label('Precio de venta')
                    ->prefix('RD$')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required()
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (float|int|string|null $state): int => (int) round(((float) $state) * 100)),
                Select::make('type')
                    ->label('Tipo')
                    ->options(ProductType::class)
                    ->default(ProductType::Simple)
                    ->required()
                    ->live()
                    // El tipo define cómo se calcula el costo y el consumo:
                    // se elige al crear y no cambia (se crea otro producto).
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (mixed $state): ?string => match (true) {
                        $state instanceof ProductType => $state->description(),
                        is_string($state) => ProductType::tryFrom($state)?->description(),
                        default => null,
                    }),
                Toggle::make('track_stock')
                    ->label('Descuenta inventario')
                    ->live()
                    ->visible(fn (mixed $get): bool => ProductType::tryFrom((string) self::rawType($get('type'))) !== ProductType::Recipe)
                    ->helperText('Una cerveza descuenta una cerveza: vincúlalo a su insumo.'),
                Select::make('inventory_item_id')
                    ->label('Insumo que descuenta')
                    ->relationship('inventoryItem', 'name')
                    ->preload()
                    ->searchable()
                    ->visible(fn (mixed $get): bool => $get('track_stock') === true
                        && ProductType::tryFrom((string) self::rawType($get('type'))) !== ProductType::Recipe)
                    ->required(fn (mixed $get): bool => $get('track_stock') === true
                        && ProductType::tryFrom((string) self::rawType($get('type'))) !== ProductType::Recipe),
                Repeater::make('recipeItems')
                    ->label('Receta (escandallo)')
                    ->relationship()
                    ->visible(fn (mixed $get): bool => ProductType::tryFrom((string) self::rawType($get('type'))) === ProductType::Recipe)
                    ->schema([
                        Select::make('inventory_item_id')
                            ->label('Insumo')
                            ->relationship('inventoryItem', 'name')
                            ->preload()
                            ->searchable()
                            ->distinct()
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()
                            ->minValue(0.001)
                            ->step(0.001)
                            ->required()
                            ->helperText('En la unidad base del insumo (ml, g o unidades).'),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->validationMessages(['min' => 'Una receta necesita al menos un insumo.'])
                    ->defaultItems(1)
                    ->addActionLabel('Añadir insumo')
                    ->columnSpanFull(),
                Toggle::make('active')
                    ->label('Activo')
                    ->default(true)
                    ->helperText('Un producto inactivo no aparece en el POS; nunca se borra, para no tocar ventas históricas.'),
            ]);
    }

    private static function rawType(mixed $state): string
    {
        return $state instanceof ProductType ? $state->value : (string) $state;
    }
}
