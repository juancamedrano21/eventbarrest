<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Exceptions\PaymentsException;
use CyberSource\Api\CustomerPaymentInstrumentApi;
use CyberSource\ApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `DELETE /tms/v2/customers/{c}/payment-instruments/{pi}` — quitar la tarjeta
 * de la bóveda.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ESTA LLAMADA VA ANTES QUE EL BORRADO LOCAL, SIEMPRE.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Una fila local que desaparece con el token vivo es una tarjeta fantasma: el
 * asistente cree haberla quitado y sigue siendo cobrable. Por eso quien llama
 * borra aquí primero y solo borra su fila si esto no revienta. El orden
 * inverso —fila primero, bóveda después— deja el peor estado posible en
 * cuanto falla la red, y encima sin nada con que encontrar el token después,
 * porque el id vivía en la fila que ya no está.
 *
 * Que la tarjeta ya no esté allí (404 o 410, medidos) NO es un fallo: es el
 * mismo destino. Ver `AccionSobreLaBoveda` para el porqué de los dos códigos.
 *
 * La clase NO es final: `enviar()` es la costura de los tests.
 */
class BorrarTarjetaDeLaBoveda extends AccionSobreLaBoveda
{
    private const RUTA = 'DELETE /tms/v2/customers/{c}/payment-instruments/{pi}';

    public function __invoke(string $customerTokenId, string $paymentInstrumentId): void
    {
        $customerTokenId = self::exigirToken('customerId', $customerTokenId);
        $paymentInstrumentId = self::exigirToken('paymentInstrumentId', $paymentInstrumentId);

        Log::info('[Pagos] ──▶ '.self::RUTA, [
            'customer' => self::huella($customerTokenId),
            'payment_instrument' => self::huella($paymentInstrumentId),
            'host' => $this->cliente->host(),
        ]);

        try {
            $this->enviar($customerTokenId, $paymentInstrumentId);
        } catch (ApiException $e) {
            if (self::yaNoEsta((int) $e->getCode())) {
                Log::info('[Pagos] ◀── la tarjeta ya no estaba en la bóveda', [
                    'payment_instrument' => self::huella($paymentInstrumentId),
                    'http_status' => (int) $e->getCode(),
                ]);

                return;
            }

            throw PaymentsException::bovedaNoDisponible(self::RUTA, (int) $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            throw PaymentsException::bovedaNoDisponible(self::RUTA, 0, $e->getMessage());
        }

        Log::info('[Pagos] ◀── tarjeta borrada de la bóveda', [
            'payment_instrument' => self::huella($paymentInstrumentId),
        ]);
    }

    protected function enviar(string $customerTokenId, string $paymentInstrumentId): void
    {
        $api = new CustomerPaymentInstrumentApi($this->cliente->apiClient());

        $api->deleteCustomerPaymentInstrumentWithHttpInfo($customerTokenId, $paymentInstrumentId);
    }
}
