<?php

declare(strict_types=1);

namespace App\Domains\Operations\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Deriva del tipo de cuenta, no se elige suelto: un negocio solo tiene
 * sucursales y un organizador solo tiene puntos de venta dentro de eventos.
 */
enum OperatingUnitType: string implements HasLabel
{
    case Branch = 'branch';
    case EventOutlet = 'event_outlet';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Branch => 'Sucursal',
            self::EventOutlet => 'Punto de venta',
        };
    }
}
