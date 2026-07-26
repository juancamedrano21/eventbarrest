<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
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
            self::Draft => 'Borrador',
            self::Active => 'En curso',
            self::Closed => 'Cerrado',
            self::Settled => 'Liquidado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Closed => 'warning',
            self::Settled => 'info',
        };
    }

    /** Ya no se vende: el evento terminó. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Closed, self::Settled], true);
    }
}
