<?php

declare(strict_types=1);

namespace App\Domains\Kitchen;

use App\Domains\Kitchen\Models\KdsDevice;

/**
 * Lo que devuelve un enrolamiento: la tablet que acaba de nacer y su token
 * en claro.
 *
 * Existe por el token. En la base solo queda su sha256, así que este es el
 * único momento de la vida del dispositivo en que el valor real está
 * disponible — quien lo reciba lo entrega a la tablet y lo olvida; si se
 * pierde, no se recupera, se revoca y se enrola de nuevo. Devolverlo dentro
 * de un objeto con nombre y no como un string suelto es lo que hace que en
 * el sitio de la llamada se lea que eso es un secreto de un solo uso.
 */
final readonly class EnrolledDevice
{
    private function __construct(
        public string $plainToken,
        public KdsDevice $device,
    ) {}

    public static function from(string $plainToken, KdsDevice $device): self
    {
        return new self($plainToken, $device);
    }
}
