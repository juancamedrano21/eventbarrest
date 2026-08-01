<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashSessionStatus: string implements HasLabel
{
    case Open = 'open';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Closed => 'Cerrada',
        };
    }
}
