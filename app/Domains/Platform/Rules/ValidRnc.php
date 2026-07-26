<?php

declare(strict_types=1);

namespace App\Domains\Platform\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RNC dominicano: 9 dígitos (persona jurídica) o 11 (cédula de persona
 * física). Se aceptan guiones y espacios como formato de entrada.
 *
 * Solo valida el formato. El dígito verificador (algoritmo DGII) queda
 * pendiente de confirmar con el contador antes de implementarse — ver
 * pregunta abierta P1 del documento de arquitectura.
 */
class ValidRnc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El RNC debe ser una cadena de texto.');

            return;
        }

        $digits = preg_replace('/[\s-]/', '', $value);

        if (! is_string($digits) || preg_match('/^(\d{9}|\d{11})$/', $digits) !== 1) {
            $fail('El :attribute debe ser un RNC de 9 dígitos o una cédula de 11 dígitos.');
        }
    }

    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s-]/', '', $value);
    }
}
