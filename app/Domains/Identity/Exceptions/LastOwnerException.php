<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

class LastOwnerException extends RuntimeException
{
    public static function cannotDemote(string $name): self
    {
        return new self(
            "[{$name}] es el único dueño del negocio: nombra otro dueño antes de cambiarle el rol."
        );
    }

    public static function cannotDelete(string $name): self
    {
        return new self(
            "[{$name}] es el único dueño del negocio y no puede eliminarse."
        );
    }
}
