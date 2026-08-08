<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\Services\CybersourceClient;
use CyberSource\Api\VoidApi;
use CyberSource\ApiException;
use CyberSource\Model\VoidPaymentRequest;
use CyberSource\ObjectSerializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /pts/v2/payments/{id}/voids` — deshacer un cobro del mismo día.
 *
 * Existe por el cobro de VERIFICACIÓN del alta de tarjeta: Cybersource
 * tokeniza dentro de una autorización, así que guardar una tarjeta obliga a
 * cobrar algo. Ese algo es simbólico y no es una compra, así que se anula en
 * el acto — cobrar de verdad llega con el pedido móvil.
 *
 * Medido contra apitest el 2026-08-07: `voidPayment` sobre un cobro con
 * `capture: true` devuelve **201** con `status: VOIDED` y
 * `voidAmountDetails.voidAmount` igual al importe cobrado.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ESTO NO ES UN PASO DEL ALTA: ES UNA LIMPIEZA POSTERIOR.
 * ─────────────────────────────────────────────────────────────────────────
 * La acción revienta si la anulación no se pudo hacer, pero quien la llama en
 * el alta NO deja caer el error al asistente: para cuando esto corre, la
 * tarjeta ya está guardada y usable, que es lo que él pidió. Fallar el alta
 * entera por no haber podido devolver un peso sería castigar al asistente por
 * un problema nuestro, y encima le dejaría la tarjeta guardada igual —el
 * token ya existe— o nos obligaría a borrarla para nada. Lo que queda
 * pendiente es devolver el importe, y eso se ve en el log.
 *
 * La clase NO es final: `enviar()` es la costura de los tests.
 */
class AnularCobro
{
    public function __construct(private readonly CybersourceClient $cliente) {}

    /**
     * @param  string  $transactionId  El `id` de la respuesta del cobro
     * @param  string  $referencia  La nuestra, para conciliar
     */
    public function __invoke(string $transactionId, string $referencia): void
    {
        if (trim($transactionId) === '') {
            throw PaymentsException::anulacionNoRealizada($referencia, null, 'sin id de transacción que anular');
        }

        Log::info('[Pagos] ──▶ POST /pts/v2/payments/{id}/voids', [
            'referencia' => $referencia,
            'transaction_id' => $transactionId,
            'host' => $this->cliente->host(),
        ]);

        try {
            $cuerpo = $this->enviar(trim($transactionId), $referencia);
        } catch (ApiException $e) {
            throw PaymentsException::anulacionNoRealizada($referencia, $transactionId, $e->getMessage());
        } catch (Throwable $e) {
            throw PaymentsException::anulacionNoRealizada($referencia, $transactionId, $e->getMessage());
        }

        $estado = is_string($cuerpo['status'] ?? null) ? $cuerpo['status'] : null;

        // Un 2xx con un estado que no es VOIDED no es una anulación: es una
        // respuesta que no dice que se haya deshecho nada. Se trata como
        // fallo, porque el dinero sigue cobrado — el mismo criterio que hace
        // de `body.status` el único árbitro en el cobro.
        if ($estado !== 'VOIDED') {
            throw PaymentsException::anulacionNoRealizada(
                $referencia,
                $transactionId,
                'la respuesta no dice VOIDED sino '.($estado ?? 'nada'),
            );
        }

        Log::info('[Pagos] ◀── cobro anulado', [
            'referencia' => $referencia,
            'transaction_id' => $transactionId,
            'anulacion_id' => is_string($cuerpo['id'] ?? null) ? $cuerpo['id'] : null,
        ]);
    }

    /**
     * La ida a la red, y nada más.
     *
     * Con el ApiClient COMPARTIDO y sin `v-c-idempotency-id`: la llave del
     * cobro pertenece al cobro, y colgársela a la anulación sería pedirle a
     * Cybersource la respuesta cacheada de aquella llamada.
     *
     * @return array<string, mixed>
     */
    protected function enviar(string $transactionId, string $referencia): array
    {
        $api = new VoidApi($this->cliente->apiClient());

        $peticion = $this->cliente->sdkModel(
            ['clientReferenceInformation' => ['code' => $referencia]],
            VoidPaymentRequest::class,
        );

        [$modelo] = $api->voidPaymentWithHttpInfo($peticion, $transactionId);

        $decodificado = json_decode(
            (string) json_encode(ObjectSerializer::sanitizeForSerialization($modelo)),
            true,
        );

        return is_array($decodificado) ? $decodificado : [];
    }
}
