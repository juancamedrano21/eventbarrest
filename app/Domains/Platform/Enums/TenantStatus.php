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
