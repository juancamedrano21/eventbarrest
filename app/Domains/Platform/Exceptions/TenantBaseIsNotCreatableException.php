<?php

declare(strict_types=1);

namespace App\Domains\Platform\Exceptions;

use RuntimeException;

/**
 * Toda cuenta nace en un mundo. Crear por la base dejaría el mundo al azar
 * del default de la columna — y una cuenta sin roles aprovisionados.
 */
class TenantBaseIsNotCreatableException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Tenant es la vista de plataforma: las cuentas nacen en su mundo, como '.
            'BusinessAccount (negocios) u OrganizerAccount (eventos), vía CreateTenant.'
        );
    }
}
