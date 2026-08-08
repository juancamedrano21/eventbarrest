<?php

declare(strict_types=1);

namespace App\Domains\EventApp;

use App\Domains\EventApp\Models\EventAppCard;

/**
 * Cómo acabó un intento de guardar una tarjeta. Tres desenlaces y ni uno más.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * «ME DIJERON QUE NO» E «INCIERTO» SON DOS COSAS, TAMBIÉN AQUÍ.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * El cimiento de pagos ya paga esa distinción una vez (`DesenlaceDeCobro`), y
 * perderla al subir de capa la haría inútil: si el alta contestara «tarjeta
 * rechazada» cuando en realidad no sabe si el cobro de verificación pasó, la
 * app enseñaría un botón de reintentar y el segundo intento cobraría otra vez
 * — el doble cobro que todo el cimiento existe para evitar.
 *
 * Por eso hay un tercer caso y no un booleano. El controlador lo traduce a
 * `201`, `422 tarjeta_rechazada` y `409 verificacion_incierta`, y ese 409
 * tiene su propio texto en la app: «no pudimos confirmarlo, revisa tus
 * tarjetas en un momento», nunca «vuelve a intentarlo».
 */
final readonly class AltaDeTarjeta
{
    private function __construct(
        public ?EventAppCard $tarjeta,
        public ?string $motivo,
        public bool $esIncierta,
    ) {}

    public static function guardada(EventAppCard $tarjeta): self
    {
        return new self($tarjeta, null, false);
    }

    /**
     * @param  string  $motivo  Legible para el asistente: sale de
     *                          `errorInformation.message` de Cybersource, no
     *                          de un código crudo.
     */
    public static function rechazada(string $motivo): self
    {
        return new self(null, $motivo, false);
    }

    /**
     * No se sabe si el cobro de verificación llegó a hacerse, así que tampoco
     * si la tarjeta quedó tokenizada. NO se guarda fila: una fila con tokens
     * inventados es peor que no tener tarjeta.
     */
    public static function incierta(): self
    {
        return new self(null, null, true);
    }

    public function fueGuardada(): bool
    {
        return $this->tarjeta !== null;
    }
}
