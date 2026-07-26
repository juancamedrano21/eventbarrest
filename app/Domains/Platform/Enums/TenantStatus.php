<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TenantStatus: string implements HasColor, HasLabel
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * Filament devuelve el enum ya convertido cuando el campo se declara con
     * ->options(self::class), y un string cuando viene de la petición.
     */
    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Trial => 'Prueba',
            self::Active => 'Activo',
            self::Suspended => 'Suspendido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trial => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}
