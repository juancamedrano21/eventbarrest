<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Correo;

use RuntimeException;

/**
 * El transporte que ocupa PRODUCCIÓN mientras no exista proveedor de correo:
 * enviar falla ruidoso, con un mensaje que dice qué configurar.
 *
 * Existe porque la alternativa era peor y silenciosa: sin esta puerta, un
 * despliegue tal cual escribía cada OTP de cada asistente EN CLARO en
 * storage/logs — un fichero que ven el despliegue, los respaldos y cualquier
 * agregador de logs — y lo único que lo «impedía» era un comentario en el
 * transporte del log. Un 500 operable en la primera petición de código se ve
 * y se arregla en minutos; los códigos filtrados al log no se ven nunca.
 *
 * El día que llegue el proveedor real, su implementación sustituye a ESTA en
 * la rama de producción del binding (AppServiceProvider) y nada más cambia.
 */
class TransporteDeCodigosSinProveedor implements TransporteDeCodigos
{
    public function enviar(string $email, string $codigo): void
    {
        throw new RuntimeException(
            'No hay proveedor de correo configurado para los códigos de entrada de la app del asistente: '
            .'en producción el código no puede ir al log. Registra la implementación real de '
            .'TransporteDeCodigos en AppServiceProvider antes de abrir esta puerta.'
        );
    }
}
