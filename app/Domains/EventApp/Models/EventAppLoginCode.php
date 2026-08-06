<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * El código de entrada vigente de un email. Una fila por buzón —el índice
 * único lo garantiza—, así que pedir un código nuevo pisa esta y el anterior
 * deja de valer en el acto.
 *
 * Lo que muere aquí es siempre el CÓDIGO, nunca la cuenta: el quinto fallo
 * lo quema (`failed_attempts`), los diez minutos lo caducan, y acertar lo
 * consume. En los tres casos la salida es la misma y gratuita — pedir otro.
 * No existe «cuenta bloqueada», que sería un botón de apagado que cualquiera
 * puede pulsar contra un buzón ajeno.
 *
 * @property int $id
 * @property string $email Normalizado: minúscula y sin espacios
 * @property string $code_hash sha256 del código de 6 dígitos
 * @property int $failed_attempts
 * @property Carbon $expires_at
 */
class EventAppLoginCode extends Model
{
    /**
     * El quinto fallo quema el código. Cinco y no tres: quien teclea de un
     * SMS o de otro pantallazo se equivoca dos o tres veces sin mala fe, y
     * el espacio es de un millón de combinaciones en diez minutos — cinco
     * intentos no compran nada a quien adivina.
     */
    public const INTENTOS_MAXIMOS = 5;

    /** Diez minutos: lo que tarda un correo lento, no una tarde de reintentos. */
    public const MINUTOS_DE_VIDA = 10;

    protected $fillable = [
        'email',
        'code_hash',
        'failed_attempts',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function estaCaducado(): bool
    {
        return $this->expires_at->isPast();
    }

    public function estaQuemado(): bool
    {
        return $this->failed_attempts >= self::INTENTOS_MAXIMOS;
    }

    /**
     * Registra un fallo donde no se puede perder: en la BASE. increment()
     * emite `failed_attempts = failed_attempts + 1`, así que dos fallos en
     * vuelo suman dos aunque ambos leyeran la fila antes de que ninguno
     * escribiera. Con el valor absoluto (leer, sumar en PHP, guardar) cada
     * tanda concurrente costaba UN intento: el tope de cinco se
     * multiplicaba por el número de trabajadores en paralelo y la
     * aritmética del ADR-011 («cinco intentos no compran nada a quien
     * adivina») dejaba de ser cierta.
     *
     * El quemado se sigue mirando ANTES de comparar, sobre la lectura
     * fresca de cada petición: mirar además el valor posterior al
     * incremento no cambiaría ninguna respuesta —este camino ya devuelve
     * el mismo null—, y el intento siguiente lee de la base un contador
     * que ya no miente.
     */
    public function registrarFallo(): void
    {
        $this->increment('failed_attempts');
    }

    /**
     * Gasta el código, y quien decide es la BASE: el DELETE devuelve
     * cuántas filas borró y solo quien borró UNA emite sesión. Dos canjes
     * en vuelo del mismo código bueno no pueden abrir dos sesiones — el
     * segundo borra cero filas y recibe el mismo null que un código
     * equivocado. Con el delete() del modelo sí podían: contesta true
     * mientras la instancia crea existir, aunque otro proceso ya hubiera
     * borrado la fila.
     *
     * El where por code_hash cierra la carrera hermana: si un /codigo
     * simultáneo ya pisó la fila con un código nuevo, este canje no puede
     * gastar un código que ya no es el suyo.
     */
    public function gastar(): bool
    {
        return self::query()
            ->whereKey($this->id)
            ->where('code_hash', $this->code_hash)
            ->delete() === 1;
    }
}
