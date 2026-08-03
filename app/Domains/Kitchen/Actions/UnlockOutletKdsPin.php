<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\Operations\Models\OperatingUnit;

/**
 * Suelta el bloqueo del PIN de un puesto sin cambiar el PIN.
 *
 * Este botón no es una comodidad, es la contrapartida obligatoria del
 * bloqueo. El código del comercio es público por diseño, así que cualquiera
 * que lo lea puede quemar diez intentos contra un puesto y dejarlo sin
 * poder enrolar tabletas justo el día del montaje. Sin una forma de
 * soltarlo en el acto, el freno que nos protege del ataque a ciegas se
 * convierte en el ataque.
 *
 * Deja el PIN intacto a propósito: el que lo lleva escrito sigue pudiendo
 * entrar, y no hay que volver a repartir nada por el recinto. Si además se
 * sospecha que el PIN se filtró, lo que toca es RotateOutletKdsPin.
 */
class UnlockOutletKdsPin
{
    public function __invoke(OperatingUnit $unit): void
    {
        $unit->setAttribute('kds_pin_failed_attempts', 0);
        $unit->setAttribute('kds_pin_locked_until', null);

        $unit->save();
    }
}
