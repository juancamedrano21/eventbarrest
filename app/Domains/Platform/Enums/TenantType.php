<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Los dos mundos de la plataforma. No es una modalidad que se active dentro de
 * una cuenta: se elige al dar de alta y no cambia, porque de él dependen la
 * estructura operativa entera y todo lo que ya se haya vendido bajo ella.
 *
 * Un evento nunca comparte datos con un negocio. Si un negocio cliente quiere
 * una barra en un festival, esa barra se crea dentro del evento del organizador.
 */
enum TenantType: string implements HasColor, HasLabel
{
    case Business = 'business';
    case Organizer = 'organizer';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Business => 'Negocio',
            self::Organizer => 'Organizador de eventos',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Business => 'info',
            self::Organizer => 'warning',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Business => 'Bar, restaurante o discoteca con operación permanente. Opera con sucursales.',
            self::Organizer => 'Productora de festivales y ferias. Opera con eventos, y dentro de cada uno sus barras y cocinas.',
        };
    }
}
