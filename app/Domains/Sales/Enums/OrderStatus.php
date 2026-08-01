<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Paid => 'Cobrada',
            self::Void => 'Anulada',
        };
    }
}
