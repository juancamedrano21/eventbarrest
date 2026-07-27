<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Unidad base en la que se cuenta un insumo. Todo lo que lo toque (recetas,
 * compras, mermas) habla en esta unidad; las conversiones de presentación
 * (una botella de 750 ml) se resuelven al comprar, no aquí.
 */
enum MeasurementUnit: string implements HasLabel
{
    case Milliliter = 'ml';
    case Gram = 'g';
    case Unit = 'unidad';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Milliliter => 'Mililitros (ml)',
            self::Gram => 'Gramos (g)',
            self::Unit => 'Unidades',
        };
    }

    public function short(): string
    {
        return $this->value;
    }
}
