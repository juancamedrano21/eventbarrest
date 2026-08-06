<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Actions;

use App\Domains\EventApp\Correo\TransporteDeCodigos;
use App\Domains\EventApp\Models\EventAppLoginCode;

/**
 * Emite el código de entrada de un email y lo pone en camino al buzón.
 *
 * AQUÍ NO SE MIRA SI LA CUENTA EXISTE, Y ESA AUSENCIA ES LA GARANTÍA. El 202
 * de esta puerta promete no decir si un email estaba registrado, y la manera
 * más sólida de que ningún oráculo se cuele —ni por el cuerpo, ni por el
 * código de estado, ni por el reloj— es que el camino sea LITERALMENTE el
 * mismo: mismo trabajo, mismas consultas, misma respuesta, exista la cuenta
 * o no. No hay rama que igualar porque no hay rama.
 *
 * El código se genera con random_int (CSPRNG del sistema), nunca con rand:
 * seis dígitos ya son pocos, y encima predecibles serían cero. Con ceros a
 * la izquierda incluidos — «042317» vale, y descartarlo recortaría el
 * espacio un diez por ciento.
 */
class IssueLoginCode
{
    public function __construct(
        private readonly TransporteDeCodigos $transporte,
    ) {}

    /**
     * @param  string  $email  Ya normalizado (minúscula, sin espacios)
     */
    public function __invoke(string $email): void
    {
        // Poda oportunista: al emitir se barren los códigos CADUCADOS de
        // cualquier buzón. Sin esto la tabla crecía una fila permanente por
        // cada dirección que alguien tecleó una vez —elegidas por quien
        // ataca, que rota buzones inventados— y ese crecimiento no tenía
        // dueño. Va en este camino y no en un comando programado para que
        // el ritmo del barrido quede atado al de la siembra: quien más
        // filas siembra, más barre. Es un DELETE sobre el índice de
        // expires_at —barato— y no toca ningún código vivo. Y no consulta
        // cuentas: la garantía del 202 sin oráculo sigue intacta.
        EventAppLoginCode::query()->where('expires_at', '<', now())->delete();

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // updateOrCreate sobre el índice único del email: pedir un código
        // nuevo PISA el anterior, que deja de valer en el acto — un solo
        // código vigente por buzón. El contador de fallos vuelve a cero
        // porque es del código, no de la persona: el código nuevo nace
        // entero.
        EventAppLoginCode::query()->updateOrCreate(
            ['email' => $email],
            [
                'code_hash' => hash('sha256', $codigo),
                'failed_attempts' => 0,
                'expires_at' => now()->addMinutes(EventAppLoginCode::MINUTOS_DE_VIDA),
            ],
        );

        $this->transporte->enviar($email, $codigo);
    }
}
