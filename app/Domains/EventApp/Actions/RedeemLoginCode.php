<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Actions;

use App\Domains\EventApp\IssuedSession;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppLoginCode;
use App\Domains\EventApp\Models\EventAppSession;
use Illuminate\Support\Str;

/**
 * Canjea un código de entrada por una sesión. Null = código inválido, y es
 * un null solo: incorrecto, caducado y quemado devuelven LO MISMO a
 * propósito, para que probar códigos no cuente nada de nadie — ni siquiera
 * si alguien pidió uno.
 *
 * Primer ingreso y reingreso son el mismo camino: si el email no tenía
 * cuenta, se crea aquí (registro); si la tenía, se entra en ella y el
 * `nombre` que venga se IGNORA — cambiarse el nombre es un PATCH con sesión,
 * no un efecto lateral de teclear un código.
 */
class RedeemLoginCode
{
    /**
     * Un sha256 de relleno para el email que no tiene ningún código pedido:
     * se compara igual y se falla igual, para que ese camino no se distinga
     * del código equivocado ni en el reloj. (Con sha256 la diferencia es
     * ínfima; el gesto cuesta una línea y deja la intención escrita.)
     */
    private const HASH_TONTO = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  string  $email  Ya normalizado (minúscula, sin espacios)
     */
    public function __invoke(string $email, string $codigo, ?string $nombre): ?IssuedSession
    {
        $vigente = EventAppLoginCode::query()->where('email', $email)->first();

        // Se compara SIEMPRE — contra el relleno si nadie pidió código —
        // para que el buzón sin código no se distinga del código equivocado
        // ni en el reloj.
        $coincide = hash_equals($vigente->code_hash ?? self::HASH_TONTO, hash('sha256', $codigo));

        // Quemado y caducado se miran ANTES de dar por buena la comparación:
        // al quinto fallo el código está muerto también para quien por fin
        // lo teclea bien. Si acertar reviviera un código quemado, el freno
        // no habría frenado nada — los cinco intentos serían solo los cinco
        // primeros.
        if ($vigente === null || $vigente->estaQuemado() || $vigente->estaCaducado()) {
            return null;
        }

        if (! $coincide) {
            // El fallo quema INTENTOS DEL CÓDIGO, jamás nada de la cuenta:
            // la cuenta ni se consulta en este camino. Pedir otro código
            // siempre es gratis y siempre nace entero. El contador sube
            // ATÓMICO en la base (ver registrarFallo): si subiera en PHP,
            // una tanda de intentos en paralelo costaría un solo intento.
            $vigente->registrarFallo();

            return null;
        }

        // De un solo uso, y el gasto lo DECIDE la base (ver gastar): dos
        // canjes en vuelo del mismo código no pueden emitir dos sesiones.
        // Quien lo intercepte después de usado tiene seis dígitos que ya no
        // abren nada.
        if (! $vigente->gastar()) {
            return null;
        }

        $cuenta = EventAppAccount::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $nombre],
        );

        $claro = Str::random(64);

        EventAppSession::query()->create([
            'event_app_account_id' => $cuenta->id,
            'token_hash' => hash('sha256', $claro),
            'last_used_at' => now(),
        ]);

        return IssuedSession::from($claro, $cuenta);
    }
}
