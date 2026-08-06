<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Correo;

/**
 * Cómo le llega el código de entrada al buzón del asistente.
 *
 * Es una interfaz y no una llamada directa a Mail porque el proveedor de
 * correo real es de OTRO slice: hoy no hay ninguno y el código se escribe en
 * el log. El día que llegue, se escribe otra implementación y se cambia el
 * binding en AppServiceProvider — el código que DECIDE (emitir, canjear,
 * frenar) no se toca, que es exactamente lo que este contrato existe para
 * garantizar.
 *
 * Quien implemente esto tiene el código EN CLARO entre las manos: se entrega
 * y se olvida, nunca se persiste — en la base solo vive su sha256.
 */
interface TransporteDeCodigos
{
    public function enviar(string $email, string $codigo): void;
}
