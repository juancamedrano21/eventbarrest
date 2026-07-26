<?php

declare(strict_types=1);

namespace App\Domains\Operations\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OperatingUnitStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Closed = 'closed';
    case Settled = 'settled';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Closed => 'Cerrada',
            self::Settled => 'Liquidada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Closed => 'warning',
            self::Settled => 'info',
        };
    }
}
