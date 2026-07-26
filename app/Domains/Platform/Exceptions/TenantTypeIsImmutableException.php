<?php

declare(strict_types=1);

namespace App\Domains\Platform\Exceptions;

use RuntimeException;

/**
 * Cambiar el tipo de una cuenta dejaría huérfana toda su estructura operativa:
 * las sucursales de un negocio no tienen sentido en un organizador, y sus
 * eventos no lo tienen en un negocio — por no hablar de lo ya vendido.
 */
class TenantTypeIsImmutableException extends RuntimeException
{
    public static function forTenant(string $name): self
    {
        return new self(
            "El tipo de la cuenta [{$name}] se fija al darla de alta y no puede cambiarse: ".
            'toda su estructura operativa y sus ventas dependen de él.'
        );
    }
}
