<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductType: string implements HasLabel
{
    case Simple = 'simple';
    case Recipe = 'recipe';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Simple => 'Sencillo',
            self::Recipe => 'Con receta',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Simple => 'Se vende tal cual: una cerveza, un refresco. Puede descontar su propio inventario.',
            self::Recipe => 'Se prepara con insumos (escandallo): un cóctel, un plato. Su costo sale de la receta.',
        };
    }
}
