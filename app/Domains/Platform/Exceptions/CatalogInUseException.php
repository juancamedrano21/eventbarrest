<?php

declare(strict_types=1);

namespace App\Domains\Platform\Exceptions;

use RuntimeException;

class CatalogInUseException extends RuntimeException
{
    public static function vendorType(string $name): self
    {
        return new self("[{$name}] tiene comercios clasificados: reclasifícalos antes de eliminarlo.");
    }

    public static function foodType(string $name): self
    {
        return new self("[{$name}] tiene comercios clasificados: reclasifícalos antes de eliminarlo.");
    }
}
