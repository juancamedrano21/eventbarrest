<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * De dónde sale lo que se vende. No es decorativo: decidirá qué parte del
 * catálogo ve cada POS (una barra no muestra platos) y por qué impresora
 * salen las comandas.
 */
enum DispatchArea: string implements HasLabel
{
    case Bar = 'bar';
    case Kitchen = 'kitchen';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Bar => 'Barra',
            self::Kitchen => 'Cocina',
        };
    }
}
