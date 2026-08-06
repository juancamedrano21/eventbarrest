<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Correo;

use Illuminate\Support\Facades\Log;

/**
 * El transporte de local y desarrollo: el código va al log y de ahí lo lee
 * quien está probando. No hay proveedor de correo todavía, y la app no tiene
 * por qué saberlo — para el teléfono, el 202 es idéntico hoy y el día que un
 * proveedor real ocupe este binding.
 *
 * Sí, escribe un secreto en el log — de un solo uso, con diez minutos de
 * vida y SOLO fuera de producción. Esa condición no es esta frase: la
 * sostiene el binding de AppServiceProvider, que en producción enlaza
 * TransporteDeCodigosSinProveedor (falla ruidoso) en vez de este. La
 * implementación real de producción, cuando exista, no debe registrar
 * jamás el código.
 */
class TransporteDeCodigosAlLog implements TransporteDeCodigos
{
    public function enviar(string $email, string $codigo): void
    {
        Log::info("Código de entrada de la app del asistente para {$email}: {$codigo}");
    }
}
