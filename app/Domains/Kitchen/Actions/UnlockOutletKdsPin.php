<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\EventManagement\Models\Vendor;
use App\Domains\Operations\Models\OperatingUnit;

/**
 * Apaga el aviso de «alguien está probando PIN contra este comercio», sin
 * tocar ningún PIN.
 *
 * ESTE BOTÓN YA NO RESCATA A NINGUNA COCINA, y el nombre de la clase se ha
 * quedado atrás. Decía —y era verdad hace tres vueltas— que cualquiera que
 * leyera el código impreso en la pared podía quemar diez intentos contra un
 * puesto y dejarlo sin poder enrolar tabletas el día del montaje, así que sin
 * una forma de soltarlo en el acto el freno se convertía en el ataque. Eso ya no
 * ocurre: la racha de intentos a ciegas no cierra ninguna puerta —el PIN
 * correcto entra igual, con ella encendida y con ella apagada; lo único que hace
 * es dejar de gastar bcrypt en contestar que no— y caduca sola en quince
 * minutos. Lo único que queda por «soltar» es la cuenta y el aviso del panel.
 *
 * POR ESO EL PANEL YA NO OFRECE ESTE BOTÓN. El aviso dice lo que pasa y no pide
 * ninguna acción, porque no hay ninguna que tomar. La acción sigue viva porque
 * su ruta sigue registrada (routes/web.php, ajeno a este cambio), y hace lo
 * único coherente que puede hacer: limpiar la racha DEL COMERCIO al que
 * pertenece el puesto. Recibe un puesto y no un comercio porque esa es la firma
 * que le pasa el controlador del panel; si algún día se retira la ruta, se
 * retiran las tres cosas juntas.
 *
 * Y NO ES UNA CUENTA POR PUESTO, aunque la URL lo parezca. Mientras la racha se
 * escribía replicada en los treinta puestos del comercio, esto limpiaba UNO y
 * los otros veintinueve seguían con el aviso encendido, así que el botón no
 * apagaba lo que decía apagar. Ahora hay una sola fila que limpiar.
 *
 * Deja el PIN intacto a propósito: el que lo lleva escrito sigue pudiendo
 * entrar, y no hay que volver a repartir nada por el recinto. Si además se
 * sospecha que el PIN se filtró, lo que toca es RotateOutletKdsPin.
 */
class UnlockOutletKdsPin
{
    public function __invoke(OperatingUnit $unit): void
    {
        Vendor::query()->withoutTenancy()
            ->whereKey($unit->getAttribute('vendor_id'))
            ->update(['kds_blind_attempts' => 0, 'kds_blind_pause_until' => null]);
    }
}
