<?php

declare(strict_types=1);

namespace App\Domains\EventApp;

use App\Domains\EventApp\Models\EventAppAccount;

/**
 * Lo que devuelve un canje de código: la cuenta y su token en claro.
 *
 * La misma figura que EnrolledDevice en el KDS, y por el mismo motivo: en la
 * base solo queda el sha256, así que este es el único momento en que el
 * valor real del token existe. Quien lo reciba lo manda al teléfono y lo
 * olvida; si se pierde, no se recupera — se entra de nuevo con otro código.
 */
final readonly class IssuedSession
{
    private function __construct(
        public string $plainToken,
        public EventAppAccount $cuenta,
    ) {}

    public static function from(string $plainToken, EventAppAccount $cuenta): self
    {
        return new self($plainToken, $cuenta);
    }
}
