<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lado padre de la herencia sobre una sola tabla (STI).
 *
 * El padre es la vista neutral (plataforma, reportería); los hijos son los
 * mundos. Cada fila se hidrata como la clase de su mundo según la columna
 * discriminadora, así que el código de un mundo nunca necesita preguntar
 * "¿de qué tipo eres?": recibe directamente la clase correcta.
 */
trait HasChildModels
{
    /**
     * Mapa valor de la columna discriminadora => clase hija.
     *
     * @return array<string, class-string<Model>>
     */
    abstract public static function childTypes(): array;

    public static function childTypeColumn(): string
    {
        return 'type';
    }

    /**
     * @param  array<string, mixed>|object  $attributes
     */
    public function newFromBuilder($attributes = [], $connection = null): static
    {
        $attributes = (array) $attributes;

        $type = $attributes[static::childTypeColumn()] ?? null;
        $class = is_string($type) ? (static::childTypes()[$type] ?? static::class) : static::class;

        if ($class === static::class) {
            return parent::newFromBuilder($attributes, $connection);
        }

        // Recursivo a propósito: en la clase hija, su tipo se resuelve a sí
        // misma y cae en la rama de arriba, ya correctamente tipada.
        $child = (new $class)->newFromBuilder($attributes, $connection);

        if (! $child instanceof static) {
            throw new LogicException(sprintf(
                'El mapa childTypes() de %s devolvió %s, que no desciende de ella.',
                static::class,
                $child::class,
            ));
        }

        return $child;
    }
}
