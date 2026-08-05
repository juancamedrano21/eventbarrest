<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Exceptions;

use RuntimeException;

/**
 * Errores de la puerta de la app del asistente. Como en Sales y en Kitchen,
 * cada uno lleva su código estable: quien decide por el código no se rompe
 * el día que alguien reescribe el mensaje.
 *
 * Ninguno de estos llega hoy al teléfono. Los tres endpoints son de solo
 * lectura y sus dos negativas —evento y comercio— se contestan como 404 en
 * el sitio donde se resuelven, con la forma exacta del contrato. Lo que se
 * lanza aquí son fallos de EMISIÓN del código público, que ocurren en el
 * panel o en un comando, delante de una persona.
 */
class EventAppException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'event_app_error',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    /** Ocho caracteres sobre 31 símbolos: si chocan cinco veces, algo va mal. */
    public static function codigoAgotado(): self
    {
        return new self(
            'No pudimos generar un código libre para el evento. Inténtalo de nuevo.',
            'event_public_code_exhausted',
            500,
        );
    }

    public static function codigoOcupado(string $codigo): self
    {
        return new self(
            "El código «{$codigo}» ya lo usa otro evento de la plataforma.",
            'event_public_code_taken',
        );
    }

    /**
     * El largo mínimo no es estético: un código de dos caracteres se acierta
     * a mano, y aunque detrás no haya nada que escribir, sí hay un festival
     * que no quiere que su app aparezca por accidente en el teléfono de
     * quien buscaba otro.
     */
    public static function codigoInvalido(string $codigo): self
    {
        return new self(
            "El código «{$codigo}» no vale: se admiten de 4 a 16 letras y números.",
            'event_public_code_invalid',
        );
    }
}
