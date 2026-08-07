<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\CobroEncontrado;
use App\Domains\Payments\ConciliacionDeCobro;
use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\Services\CybersourceClient;
use CyberSource\Api\SearchTransactionsApi;
use CyberSource\ApiException;
use CyberSource\Model\CreateSearchRequest;
use CyberSource\ObjectSerializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /tss/v2/searches` — ¿existe ya un cobro con esta referencia nuestra?
 *
 * Es el camino de reconciliación del doc 12 §4, y es lo que hay que hacer
 * ANTES de reintentar un cobro que salió `incierto`: si la llamada se cortó,
 * la tarjeta puede estar cobrada y la única forma de saberlo es preguntar.
 *
 * Por qué esto no es un lujo: el MID de sandbox NO honra
 * `v-c-idempotency-id` (medido el 2026-08-07 — dos llamadas con la misma
 * llave, y con la cabecera demostradamente en el request, dieron dos cobros
 * distintos y los dos AUTHORIZED). Mientras PortalDOM no habilite la
 * idempotencia, esta consulta no es un colchón: es la ÚNICA defensa contra el
 * doble cobro.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DOS COSAS MEDIDAS CONTRA apitest.cybersource.com EL 2026-08-07
 * ─────────────────────────────────────────────────────────────────────────
 *
 * 1. **El MID SÍ tiene la búsqueda habilitada.** HTTP 201, la transacción
 *    aparece buscando por `clientReferenceInformation.code`, y una referencia
 *    inexistente devuelve `totalCount: 0` limpio (no un error). No hace falta
 *    pedírselo a PortalDOM para sandbox; para el MID de producción hay que
 *    confirmarlo, y si no lo tuviera, `busquedaNoDisponible()` lo dice.
 * 2. **Hay retraso de indexado: ~5 segundos.** A 0,3 s del cobro la búsqueda
 *    devolvía 0 resultados; a 4,6 s ya devolvía la transacción. Un cero
 *    inmediato NO prueba que no se cobró, y por eso la decisión de reintentar
 *    vive en `ConciliacionDeCobro::sePuedeReintentar()`, que exige que haya
 *    pasado el tiempo.
 *
 * Y lo que NO hace esta clase: no se traga los errores. Si la búsqueda falla
 * —MID sin la habilitación, 401, red— revienta, porque «no encontré nada» y
 * «no pude mirar» llevan a decisiones opuestas y confundirlas es el doble
 * cobro que se venía a evitar.
 */
class BuscarCobroPorReferencia
{
    public function __construct(private readonly CybersourceClient $cliente) {}

    public function __invoke(string $referencia): ConciliacionDeCobro
    {
        $referencia = trim($referencia);

        if ($referencia === '') {
            throw PaymentsException::referenciaVacia();
        }

        Log::info('[Pagos] ──▶ POST /tss/v2/searches', [
            'referencia' => $referencia,
            'host' => $this->cliente->host(),
        ]);

        $cuerpo = $this->llamar($referencia);

        $resumenes = $cuerpo['_embedded']['transactionSummaries'] ?? [];

        $cobros = [];
        foreach (is_array($resumenes) ? $resumenes : [] as $resumen) {
            if (is_array($resumen)) {
                $cobros[] = CobroEncontrado::desdeResumen($resumen);
            }
        }

        $conciliacion = new ConciliacionDeCobro(
            referencia: $referencia,
            total: is_int($cuerpo['totalCount'] ?? null) ? $cuerpo['totalCount'] : count($cobros),
            cobros: $cobros,
        );

        Log::info('[Pagos] ◀── búsqueda', $conciliacion->paraLog());

        return $conciliacion;
    }

    /**
     * La consulta que se manda.
     *
     * La referencia va ENTRECOMILLADA en la query: el campo es un índice de
     * texto y una referencia con un espacio o con dos puntos sin comillas
     * cambiaría la consulta en vez de fallar — o sea, contestaría por otra
     * transacción. Las comillas y las contrabarras se rechazan directamente
     * porque no hay forma de escaparlas que esté documentada.
     *
     * @return array<string, mixed>
     */
    public function consulta(string $referencia): array
    {
        if (str_contains($referencia, '"') || str_contains($referencia, '\\')) {
            throw PaymentsException::referenciaNoConsultable($referencia);
        }

        return [
            'save' => false,
            'name' => 'EBR conciliacion',
            // El huso del negocio, no UTC: los cortes de día de la casa son
            // en RD y el `submitTimeUtc` del resumen se lee contra ellos.
            'timezone' => (string) config('app.business_timezone', 'America/Santo_Domingo'),
            'query' => 'clientReferenceInformation.code:"'.$referencia.'"',
            'offset' => 0,
            // Más de uno significa que ya hubo un duplicado, y eso hay que
            // verlo entero, no recortado al primero.
            'limit' => 20,
            'sort' => 'submitTimeUtc:desc',
        ];
    }

    /**
     * La ida a Cybersource. Costura para los tests, igual que en
     * `CobrarConTarjeta`.
     *
     * Se usa el ApiClient COMPARTIDO, sin `v-c-idempotency-id`: una búsqueda
     * no cobra, y colgarle la llave del cobro sería pedirle a Cybersource la
     * respuesta cacheada de otra llamada.
     *
     * @return array<string, mixed>
     */
    protected function llamar(string $referencia): array
    {
        $consulta = $this->consulta($referencia);

        try {
            $api = new SearchTransactionsApi($this->cliente->apiClient());
            $peticion = $this->cliente->sdkModel($consulta, CreateSearchRequest::class);

            [$modelo] = $api->createSearchWithHttpInfo($peticion);

            $decodificado = json_decode(
                (string) json_encode(ObjectSerializer::sanitizeForSerialization($modelo)),
                true,
            );

            return is_array($decodificado) ? $decodificado : [];
        } catch (ApiException $e) {
            throw PaymentsException::busquedaNoDisponible($referencia, (int) $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            throw PaymentsException::busquedaNoDisponible($referencia, 0, $e->getMessage());
        }
    }
}
