<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\Operations\Models\OperatingUnit;
use Illuminate\Support\Facades\Hash;

/**
 * Emite un PIN nuevo para el puesto y lo devuelve en claro UNA vez.
 *
 * Lo genera el sistema y no se deja elegir. No es paternalismo: el PIN lo
 * pone un encargado con prisa el día del montaje, y un encargado con prisa
 * pone 123456 o el año del evento. Aquí lo único que protege la puerta es
 * este número —el código del comercio es público—, así que seis dígitos
 * aleatorios de verdad son el mínimo honesto.
 *
 * Rotar el PIN NO revoca las tabletas ya enroladas, y eso es deliberado.
 * Cada tablet vive de su propio token desde el momento en que entró; el PIN
 * solo sirve para dejar entrar a la siguiente. Si fuese al revés, cambiar
 * el PIN a mitad del festival apagaría todas las pantallas del puesto a la
 * vez. Para apagar una tablet está RevokeKdsDevice, de una en una.
 */
class RotateOutletKdsPin
{
    public function __invoke(OperatingUnit $unit): string
    {
        // Seis dígitos con los ceros a la izquierda incluidos: 000123 es tan
        // válido como 987654, y quitarlos recortaría el espacio a la mitad.
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $unit->setAttribute('kds_pin_hash', Hash::make($pin));
        $unit->setAttribute('kds_pin_set_at', now());

        // Un PIN nuevo estrena cuenta: el bloqueo que dejaron los intentos
        // contra el PIN viejo ya no protege nada y solo estorbaría al que
        // acaba de recibir el bueno.
        $unit->setAttribute('kds_pin_failed_attempts', 0);
        $unit->setAttribute('kds_pin_locked_until', null);

        $unit->save();

        return $pin;
    }
}
