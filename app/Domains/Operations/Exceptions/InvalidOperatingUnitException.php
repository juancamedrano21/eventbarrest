<?php

declare(strict_types=1);

namespace App\Domains\Operations\Exceptions;

use App\Domains\Platform\Enums\TenantType;
use RuntimeException;

class InvalidOperatingUnitException extends RuntimeException
{
    public static function baseIsNotCreatable(): self
    {
        return new self(
            'OperatingUnit es la vista neutral de reportería: las altas nacen en su mundo, '.
            'como Branch (sucursales) o EventOutlet (puntos de venta de un evento).'
        );
    }

    public static function structureIsImmutable(): self
    {
        return new self(
            'Una unidad operativa no cambia de mundo ni de evento: si dejó de operar, ciérrala por estado.'
        );
    }

    public static function outletNeedsAnEvent(): self
    {
        return new self(
            'Un punto de venta nace dentro de un evento: créalo desde el evento al que pertenece.'
        );
    }

    public static function wrongAccountType(TenantType $type): self
    {
        return new self(match ($type) {
            TenantType::Business => 'Una cuenta de negocio opera con sucursales, no con eventos ni puntos de venta.',
            TenantType::Organizer => 'Una cuenta de organizador opera con eventos: sus puntos de venta se crean dentro de un evento.',
        });
    }

    public static function eventOutsideTenant(): self
    {
        return new self('El evento indicado no pertenece a esta cuenta.');
    }
}
