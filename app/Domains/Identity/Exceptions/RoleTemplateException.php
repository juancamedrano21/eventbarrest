<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

class RoleTemplateException extends RuntimeException
{
    public static function unknownRole(string $name): self
    {
        return new self("El rol [{$name}] no existe en el catálogo de la plataforma.");
    }

    public static function unknownPermission(string $permission): self
    {
        return new self(
            "El permiso [{$permission}] no existe: un permiso sin código que lo compruebe no protege nada."
        );
    }

    public static function needsAtLeastOnePermission(): self
    {
        return new self('Un rol sin permisos no deja hacer nada: dale al menos uno.');
    }

    public static function ownerIsUntouchable(): self
    {
        return new self(
            'El rol de dueño es la raíz de cada cuenta: no se edita ni se elimina.'
        );
    }

    public static function identityIsImmutable(): self
    {
        return new self(
            'El identificador, el alcance y la marca de sistema de un rol no cambian: '
            .'son su identidad en todas las cuentas.'
        );
    }

    public static function systemTemplateCannotBeDeleted(string $name): self
    {
        return new self("[{$name}] es un rol de sistema: se puede ajustar, nunca eliminar.");
    }

    public static function templateInUse(string $name): self
    {
        return new self(
            "[{$name}] tiene usuarios asignados en alguna cuenta: quítaselo antes de eliminarlo."
        );
    }

    public static function nameTaken(string $name): self
    {
        return new self("Ya existe un rol con el identificador [{$name}].");
    }

    public static function nameReservedForSystem(string $name): self
    {
        return new self("[{$name}] es un identificador de rol de sistema: elige otro nombre.");
    }

    public static function labelNeedsLetters(string $label): self
    {
        return new self(
            "De [{$label}] no se puede derivar un identificador: el nombre necesita letras o números."
        );
    }

    public static function permissionNotForKind(string $permission, string $kind): self
    {
        return new self(
            "El permiso [{$permission}] es de administración de cuenta y no puede ir en un rol ".
            "asignable a personal de comercio [{$kind}]: rompería la frontera entre cuenta y comercio."
        );
    }

    public static function cannotGrantBeyondSelf(string $role): self
    {
        return new self(
            "No puedes conceder [{$role}]: otorga permisos que tú no tienes. ".
            'Nadie asigna por encima de su propio techo.'
        );
    }
}
