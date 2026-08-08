<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Exceptions\PaymentsException;
use CyberSource\Api\CustomerApi;
use CyberSource\ApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `DELETE /tms/v2/customers/{c}` — quitar de la bóveda el cliente que agrupa
 * las tarjetas de un asistente.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NO SE CONFÍA EN LA CASCADA, AUNQUE EXISTA.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Medido contra apitest el 2026-08-07: borrar el customer SÍ deja sus
 * payment instruments inalcanzables (un `GET` posterior devuelve 410 «Token
 * not available»). Pero ese comportamiento NO está documentado por
 * Cybersource, así que no es una promesa: es una observación de un día en un
 * MID de pruebas, y el día que cambie no habría forma de enterarse hasta
 * encontrar un token vivo que se creía borrado.
 *
 * Por eso quien borra una cuenta borra PRIMERO cada payment instrument, uno a
 * uno, y solo después llama aquí. Esta acción es el segundo paso, no el
 * atajo que ahorra el primero.
 *
 * Igual que con las tarjetas, «ya no está» (404 o 410) es éxito. Y hay un
 * matiz medido: borrar dos veces el MISMO customer devuelve 204 las dos veces
 * —la bóveda no distingue—, mientras que un id inventado devuelve 404. Los
 * tres caminos acaban en el mismo sitio a propósito.
 *
 * La clase NO es final: `enviar()` es la costura de los tests.
 */
class BorrarClienteDeLaBoveda extends AccionSobreLaBoveda
{
    private const RUTA = 'DELETE /tms/v2/customers/{c}';

    public function __invoke(string $customerTokenId): void
    {
        $customerTokenId = self::exigirToken('customerId', $customerTokenId);

        Log::info('[Pagos] ──▶ '.self::RUTA, [
            'customer' => self::huella($customerTokenId),
            'host' => $this->cliente->host(),
        ]);

        try {
            $this->enviar($customerTokenId);
        } catch (ApiException $e) {
            if (self::yaNoEsta((int) $e->getCode())) {
                Log::info('[Pagos] ◀── el cliente ya no estaba en la bóveda', [
                    'customer' => self::huella($customerTokenId),
                    'http_status' => (int) $e->getCode(),
                ]);

                return;
            }

            throw PaymentsException::bovedaNoDisponible(self::RUTA, (int) $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            throw PaymentsException::bovedaNoDisponible(self::RUTA, 0, $e->getMessage());
        }

        Log::info('[Pagos] ◀── cliente borrado de la bóveda', [
            'customer' => self::huella($customerTokenId),
        ]);
    }

    protected function enviar(string $customerTokenId): void
    {
        $api = new CustomerApi($this->cliente->apiClient());

        $api->deleteCustomerWithHttpInfo($customerTokenId);
    }
}
