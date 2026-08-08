<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\TarjetaEnLaBoveda;
use CyberSource\Api\CustomerPaymentInstrumentApi;
use CyberSource\ApiException;
use CyberSource\ObjectSerializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /tms/v2/customers/{c}/payment-instruments/{pi}` — qué sabe la bóveda
 * de una tarjeta guardada.
 *
 * Tiene dos usos, y los dos importan:
 *
 * 1. **Al guardar**, para leer marca, últimos 4 y vencimiento. La respuesta
 *    del cobro que tokeniza NO los trae (solo la marca), así que esta es la
 *    única fuente de lo que la app pinta. El porqué largo, en
 *    `TarjetaEnLaBoveda`.
 * 2. **Al comprobar un borrado**, para poder afirmar que el token ya no
 *    existe en vez de suponerlo.
 *
 * Devuelve `null` cuando la bóveda dice que ese token ya no está (404 o 410,
 * ver `AccionSobreLaBoveda`) y REVIENTA con cualquier otro fallo: «no está» y
 * «no pude mirar» no se confunden.
 *
 * La clase NO es final: `enviar()` es la costura por la que los tests
 * sustituyen la ida a la red, igual que en `CobrarConTarjeta`.
 */
class BuscarTarjetaEnLaBoveda extends AccionSobreLaBoveda
{
    private const RUTA = 'GET /tms/v2/customers/{c}/payment-instruments/{pi}';

    public function __invoke(string $customerTokenId, string $paymentInstrumentId): ?TarjetaEnLaBoveda
    {
        $customerTokenId = self::exigirToken('customerId', $customerTokenId);
        $paymentInstrumentId = self::exigirToken('paymentInstrumentId', $paymentInstrumentId);

        Log::info('[Pagos] ──▶ '.self::RUTA, [
            'customer' => self::huella($customerTokenId),
            'payment_instrument' => self::huella($paymentInstrumentId),
            'host' => $this->cliente->host(),
        ]);

        try {
            $cuerpo = $this->enviar($customerTokenId, $paymentInstrumentId);
        } catch (ApiException $e) {
            if (self::yaNoEsta((int) $e->getCode())) {
                Log::info('[Pagos] ◀── la bóveda ya no tiene esa tarjeta', [
                    'payment_instrument' => self::huella($paymentInstrumentId),
                    'http_status' => (int) $e->getCode(),
                ]);

                return null;
            }

            throw PaymentsException::bovedaNoDisponible(self::RUTA, (int) $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            throw PaymentsException::bovedaNoDisponible(self::RUTA, 0, $e->getMessage());
        }

        $tarjeta = TarjetaEnLaBoveda::desdeRespuesta($cuerpo, $paymentInstrumentId);

        // Del cuerpo NO se registra nada más que lo que la app va a enseñar:
        // lleva dentro el `instrumentIdentifier` y el billTo del titular.
        Log::info('[Pagos] ◀── tarjeta en la bóveda', [
            'payment_instrument' => self::huella($paymentInstrumentId),
            'marca' => $tarjeta->marca->value,
            'ultimos4' => $tarjeta->ultimos4,
            'vence' => $tarjeta->venceMes.'/'.$tarjeta->venceAno,
        ]);

        return $tarjeta;
    }

    /**
     * La ida a la red, y nada más.
     *
     * Con el ApiClient COMPARTIDO: una consulta no cobra, y colgarle una
     * `v-c-idempotency-id` sería pedir la respuesta cacheada de otra llamada.
     *
     * @return array<string, mixed>
     */
    protected function enviar(string $customerTokenId, string $paymentInstrumentId): array
    {
        $api = new CustomerPaymentInstrumentApi($this->cliente->apiClient());

        [$modelo] = $api->getCustomerPaymentInstrumentWithHttpInfo($customerTokenId, $paymentInstrumentId);

        $decodificado = json_decode(
            (string) json_encode(ObjectSerializer::sanitizeForSerialization($modelo)),
            true,
        );

        return is_array($decodificado) ? $decodificado : [];
    }
}
