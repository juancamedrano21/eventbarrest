<?php

declare(strict_types=1);

namespace App\Domains\Operations\Exceptions;

use App\Domains\Operations\Enums\OperatingUnitType;
use App\Domains\Platform\Enums\TenantType;
use RuntimeException;

class InvalidOperatingUnitException extends RuntimeException
{
    public static function typeMismatch(OperatingUnitType $expected, OperatingUnitType $given): self
    {
        return new self(
            'El tipo de unidad operativa lo determina el evento, no el formulario: se esperaba '.
            "[{$expected->value}] y llegó [{$given->value}]."
        );
    }

    public static function wrongAccountType(TenantType $type): self
    {
        return new self(match ($type) {
            TenantType::Business => 'Una cuenta de negocio opera con sucursales, no con puntos de venta de evento.',
            TenantType::Organizer => 'Una cuenta de organizador opera con eventos: sus puntos de venta se crean dentro de un evento.',
        });
    }

    public static function eventIsImmutable(): self
    {
        return new self(
            'Una unidad operativa no cambia de evento ni deja de ser sucursal: si dejó de operar, ciérrala por estado.'
        );
    }

    public static function eventOutsideTenant(): self
    {
        return new self('El evento indicado no pertenece a esta cuenta.');
    }
}
