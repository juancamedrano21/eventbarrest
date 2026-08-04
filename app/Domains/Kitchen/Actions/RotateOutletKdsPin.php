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
 *
 * Y AQUÍ SE ESCRIBE EL ÍNDICE CIEGO, que es lo que hace barato el alta.
 *
 * `EnrollKdsDevice` ya no prueba el bcrypt contra todos los puestos del
 * comercio: `kds_pin_index` le dice a cuál preguntar y gasta UNO. Pero ese
 * índice se deriva del PIN EN CLARO, y el PIN en claro existe exactamente en
 * dos sitios de la aplicación: en el alta, cuando el cocinero lo teclea, y
 * aquí, que es donde nace.
 *
 * Mientras esto no lo escribiera, el índice solo aparecía con la primera alta
 * CORRECTA — o sea, el día del montaje, cuando todos los puestos son recién
 * emitidos y ninguno se ha usado todavía, no existía para ninguno y cada
 * petición anónima volvía a costar un bcrypt por puesto. Medido: treinta
 * puestos recién creados desde el panel, treinta comprobaciones por intento.
 * Justo el día que más gente teclea y menos margen hay.
 *
 * Escribiéndolo aquí, un puesto sin índice deja de ser el caso normal y pasa a
 * ser lo que de verdad es: el residuo de los PIN emitidos antes de que la
 * columna existiera, que no se pueden indexar sin volver a emitirlos y que se
 * curan solos con la primera alta buena.
 *
 * Y NO TOCA NINGUNA CUENTA DE FALLOS, porque ya no hay ninguna que sea del
 * puesto: la racha de intentos a ciegas es del COMERCIO —un intento fallido no
 * identifica ningún puesto— y vive en su fila. Rotar el PIN de una barra no dice
 * nada sobre si alguien está probando PIN contra el código del comercio, así que
 * tampoco apaga ese aviso; se apaga solo en quince minutos. Ver
 * EnrollKdsDevice::anotarFallo.
 *
 * LAS DOS COLUMNAS VAN JUNTAS O NO VA NINGUNA. `kds_pin_indexed_hash` guarda la
 * huella de las tres cosas de las que depende el índice —el comercio con el que
 * se saló, el `kds_pin_hash` para el que se calculó y la llave con la que se
 * derivó—, y sin ella este método sería
 * el que estropeara el alta en vez de abaratarla: reescribe el hash, así que un
 * índice del PIN VIEJO al lado del hash del NUEVO dejaría al cocinero que teclea
 * BIEN recibiendo «revisa el código y el PIN». Un índice cuya huella no
 * corresponde sencillamente no se usa. El porqué de la mitad de la llave —y la
 * avería global que cerró— está en `EnrollKdsDevice::huellaDelIndice`.
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

        // El índice y su huella, en la MISMA escritura que el hash: es lo que
        // impide que exista un instante —ni una fila guardada a medias— en el
        // que el índice hable de un PIN y el hash de otro. La huella se calcula
        // del hash que se acaba de poner, no del que hubiera antes.
        $unit->setAttribute(
            'kds_pin_index',
            EnrollKdsDevice::indiceDelPin((int) $unit->getAttribute('vendor_id'), $pin),
        );
        $unit->setAttribute(
            'kds_pin_indexed_hash',
            EnrollKdsDevice::huellaDelIndice(
                (int) $unit->getAttribute('vendor_id'),
                (string) $unit->getAttribute('kds_pin_hash'),
            ),
        );

        $unit->save();

        return $pin;
    }
}
