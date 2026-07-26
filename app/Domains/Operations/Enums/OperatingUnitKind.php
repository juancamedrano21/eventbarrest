<?php

declare(strict_types=1);

namespace App\Domains\Operations\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qué se despacha en la unidad. No es decorativo: decide qué parte del catálogo
 * ve el POS, a qué impresora salen las comandas y cómo se agrupa la reportería
 * (bebida frente a comida).
 */
enum OperatingUnitKind: string implements HasLabel
{
    case Bar = 'bar';
    case Kitchen = 'kitchen';
    case Mixed = 'mixed';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Bar => 'Barra',
            self::Kitchen => 'Cocina',
            self::Mixed => 'Mixta',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bar => 'Solo bebida. El POS muestra únicamente productos de barra.',
            self::Kitchen => 'Solo comida. Las comandas salen por la impresora de cocina.',
            self::Mixed => 'Bebida y comida en el mismo punto: el caso normal de un restaurante.',
        };
    }
}
