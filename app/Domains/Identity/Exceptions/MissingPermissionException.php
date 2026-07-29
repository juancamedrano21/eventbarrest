<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use App\Domains\Identity\Enums\Permission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Un 403 que dice QUÉ faltó. El "403 Forbidden" seco obliga a depurar a
 * ciegas: aquí el usuario ve qué permiso necesita y a quién pedírselo.
 */
class MissingPermissionException extends AccessDeniedHttpException
{
    public static function for(Permission $permission): self
    {
        return new self(
            "No tienes el permiso «{$permission->value}» en esta cuenta. ".
            'Pídeselo al dueño de la cuenta desde Equipo → Cambiar rol.'
        );
    }

    public static function wrongWorld(string $expected): self
    {
        return new self(
            "Esta sección es solo para cuentas de {$expected}. ".
            'El tipo de cuenta se elige al darla de alta y no cambia.'
        );
    }
}
