<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\Enums\ModoDeCobro;
use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\ResultadoDeCobro;
use App\Domains\Payments\Services\CybersourceClient;
use CyberSource\Api\PaymentsApi;
use CyberSource\ApiException;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\ObjectSerializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Autorización y captura en una sola llamada a `POST /pts/v2/payments`.
 *
 * Captura inmediata (`capture: true`) y no diferida porque aquí la comida se
 * despacha al momento: no hay envío que esperar entre autorizar y cobrar.
 *
 * Tres modos, un mismo cuerpo base (ver `cuerpo()`), y una misma lectura de la
 * respuesta. Lo que no se negocia después del cobro es la guarda de
 * invariante del final: un cobro aprobado del que no vuelve la credencial se
 * trata como incidente, no como éxito.
 *
 * La clase NO es final a propósito: `enviar()` es la costura por donde los
 * tests sustituyen la ida a la red. Sin ella, todo lo que rodea a la llamada
 * —el mapeo de estados, la separación entre rechazo y silencio, la guarda de
 * invariante, el log crítico— solo se podría probar teniendo credenciales y
 * conexión, o sea: no se probaría.
 */
class CobrarConTarjeta
{
    public function __construct(private readonly CybersourceClient $cliente) {}

    public function __invoke(CobroSolicitado $cobro): ResultadoDeCobro
    {
        if ($cobro->modo === ModoDeCobro::PanDeSandbox && ! $this->cliente->esSandbox()) {
            throw PaymentsException::panSoloEnSandbox($this->cliente->host());
        }

        $cuerpo = $this->cuerpo($cobro);

        Log::info('[Pagos] ──▶ POST /pts/v2/payments', [
            'referencia' => $cobro->referencia,
            'modo' => $cobro->modo->value,
            'host' => $this->cliente->host(),
            'idempotency_key' => $cobro->idempotencyKey,
            'cuerpo' => self::redactado($cuerpo),
        ]);

        $resultado = $this->llamar($cobro, $cuerpo);

        Log::info('[Pagos] ◀── respuesta', ['referencia' => $cobro->referencia] + $resultado->paraLog());

        $this->comprobarInvariante($cobro, $resultado);

        return $resultado;
    }

    /**
     * La ida a Cybersource.
     *
     * ─────────────────────────────────────────────────────────────────────
     * AQUÍ SE SEPARA «ME DIJERON QUE NO» DE «NO SÉ SI SE COBRÓ».
     * ─────────────────────────────────────────────────────────────────────
     *
     * El SDK envuelve TODO en `ApiException`: tanto un 400 con cuerpo JSON
     * como un curl que ni siquiera conectó — `ApiClient::callApi()` lanza
     * `new ApiException($mensaje, 0, [], null)` cuando `http_code === 0`
     * (timeout, DNS, conexión cortada). Por eso el TIPO de la excepción no
     * sirve para distinguirlos: hay que mirar el código HTTP y si hay cuerpo.
     *
     * Y distinguirlos es lo único que importa de verdad en un fallo de pago:
     * un rechazo significa «no se cobró, reintenta tranquilo»; un corte
     * significa «puede que la tarjeta esté cobrada». En un festival con mala
     * señal el segundo pasa, y tratarlo como el primero es un doble cobro.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    protected function llamar(CobroSolicitado $cobro, array $cuerpo): ResultadoDeCobro
    {
        try {
            [$respuesta, $httpStatus] = $this->enviar($cobro, $cuerpo);

            // Un 2xx SIN `status` legible NO es un rechazo: es silencio con
            // buena cara. Pasa cuando la respuesta se corta a mitad —el
            // servidor ya mandó la línea de estado y el cuerpo llega
            // incompleto—, y ahí Cybersource creó la transacción: la tarjeta
            // PUEDE estar cobrada. Sin esta rama caía en `desdeRespuesta()`,
            // que sin `status` clasifica `Desconocido` → `error`, o sea el
            // camino silencioso que invita a reintentar. Es el mismo silencio
            // del `catch` de abajo, solo que envuelto en un 201.
            if ($httpStatus >= 200 && $httpStatus < 300 && ! isset($respuesta['status'])) {
                return $this->silencio(
                    $cobro,
                    'respuesta 2xx sin `status`: el cuerpo llegó incompleto',
                    $httpStatus,
                );
            }

            return ResultadoDeCobro::desdeRespuesta($respuesta, $httpStatus);
        } catch (ApiException $e) {
            if (self::hayRespuesta($e)) {
                // Un 4xx de Cybersource SÍ es una respuesta: trae `status` y
                // motivo. Se lee igual que un 201 — el árbitro sigue siendo
                // `body.status`, no el código HTTP.
                return ResultadoDeCobro::desdeRespuesta(self::cuerpoDeLaExcepcion($e), (int) $e->getCode());
            }

            // Silencio. NO se sabe si se cobró, así que ni se devuelve un
            // rechazo (que invitaría a reintentar) ni se muere callado.
            return $this->silencio($cobro, $e->getMessage(), (int) $e->getCode());
        } catch (Throwable $e) {
            // Lo que revienta ANTES o DESPUÉS de la ida a la red: armar el
            // modelo del SDK, construir el cliente, deserializar. Tampoco se
            // puede afirmar que no se cobró, así que se falla ruidoso en vez
            // de devolver nada parecido a una decisión de pago.
            Log::error('[Pagos] la llamada a Cybersource no se completó', [
                'referencia' => $cobro->referencia,
                'error' => $e->getMessage(),
            ]);

            throw PaymentsException::falloDeTransporte($e);
        }
    }

    /**
     * El desenlace de «no sé si se cobró», con su rastro.
     *
     * Está en un solo sitio porque los dos caminos que llevan aquí —el corte
     * sin respuesta y el 2xx con el cuerpo a medias— tienen que producir
     * EXACTAMENTE lo mismo: un desenlace `incierto` que el llamador no puede
     * confundir con un rechazo, y una línea de error, porque esto hay que ir a
     * mirarlo y no se ve solo.
     */
    private function silencio(CobroSolicitado $cobro, string $motivo, int $httpStatus): ResultadoDeCobro
    {
        Log::error('[Pagos] la llamada a Cybersource no se completó: PUEDE HABERSE COBRADO', [
            'referencia' => $cobro->referencia,
            'http_status' => $httpStatus,
            'error' => $motivo,
            'siguiente_paso' => 'BuscarCobroPorReferencia antes de reintentar; el reintento va con la MISMA idempotency key',
        ]);

        return ResultadoDeCobro::sinRespuesta($motivo, $httpStatus);
    }

    /**
     * La ida a la red, y NADA más: ni try/catch ni interpretación.
     *
     * Está separada de `llamar()` para que la costura de los tests sustituya
     * solo el cable. Con las dos cosas en el mismo método, un doble que
     * quisiera probar un timeout tenía que reimplementar la clasificación —o
     * sea, probarse a sí mismo—. Así el doble lanza la `ApiException` que
     * lanza el SDK de verdad y quien decide qué significa sigue siendo el
     * código de producción.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array{0: array<string, mixed>, 1: int}
     */
    protected function enviar(CobroSolicitado $cobro, array $cuerpo): array
    {
        // Cliente EFÍMERO con la cabecera de idempotencia: es lo que
        // convierte un reintento por mala señal en la misma respuesta en vez
        // de un segundo cobro.
        $api = new PaymentsApi($this->cliente->apiClientWithIdempotency($cobro->idempotencyKey));
        $peticion = $this->cliente->sdkModel($cuerpo, CreatePaymentRequest::class);

        [$modelo, $httpStatus] = $api->createPaymentWithHttpInfo($peticion);

        return [self::comoArray($modelo), (int) $httpStatus];
    }

    /**
     * ¿La excepción trae una respuesta del servidor, o es el silencio?
     *
     * Cuenta como respuesta un 2xx/4xx CON cuerpo: ahí Cybersource decidió, o
     * rechazó la petición sin llegar a procesarla, y `body.status` lo dice.
     *
     * Todo lo demás es incierto:
     *   - código 0 → curl no completó la llamada (no hay ni línea de estado);
     *   - sin cuerpo → hubo código pero nadie dijo qué pasó con el cobro;
     *   - 5xx → un 502/504 puede llegar DESPUÉS de que el emisor autorizara,
     *     y eso es exactamente «puede que se haya cobrado».
     */
    private static function hayRespuesta(ApiException $e): bool
    {
        $codigo = (int) $e->getCode();

        if ($codigo < 200 || $codigo >= 500) {
            return false;
        }

        $crudo = $e->getResponseBody();

        if (is_array($crudo) || is_object($crudo)) {
            return true;
        }

        return is_string($crudo) && trim($crudo) !== '';
    }

    /**
     * El cuerpo que se manda, armado como array.
     *
     * Es público porque es lo que hay que poder comparar sin red: una clave
     * mal escrita aquí no da error, la borra el serializador del SDK y el
     * cobro sale sin ella (ver `CybersourceClient::sdkModel()`).
     *
     * @return array<string, mixed>
     */
    public function cuerpo(CobroSolicitado $cobro): array
    {
        $cuerpo = [
            // El ancla de la conciliación y de la idempotencia de la casa:
            // mapea 1:1 con nuestra referencia de pedido.
            'clientReferenceInformation' => [
                'code' => $cobro->referencia,
            ],

            'processingInformation' => $this->processingInformation($cobro),

            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $cobro->importeFormateado(),
                    'currency' => (string) config('services.portaldom.currency', 'DOP'),
                ],
            ],

            'merchantDefinedInformation' => $this->merchantDefinedInformation($cobro),
        ];

        // Los datos de la tarjeta, según de dónde vengan.
        $cuerpo += match ($cobro->modo) {
            ModoDeCobro::TarjetaNueva => [
                'tokenInformation' => [
                    // `transientTokenJwt`, el JWT ENTERO. En `/pts/v2/payments`
                    // se llama así; en `/risk/v1/*` el mismo dato se llama
                    // `transientToken` y es solo el claim `jti`. Cambiar uno
                    // por otro da INVALID_DATA, y la documentación de
                    // PortalDOM que dice «jti» aquí está equivocada.
                    'transientTokenJwt' => (string) $cobro->transientTokenJwt,
                ],
            ],

            ModoDeCobro::TarjetaGuardada => [
                // El token de cliente lleva dentro la tarjeta y el billTo, así
                // que no viaja ni un dato de tarjeta más.
                'paymentInformation' => $cobro->paymentInstrumentId === null
                    ? ['customer' => ['id' => (string) $cobro->customerTokenId]]
                    : [
                        'customer' => ['id' => (string) $cobro->customerTokenId],
                        'paymentInstrument' => ['id' => $cobro->paymentInstrumentId],
                    ],
            ],

            ModoDeCobro::PanDeSandbox => [
                'paymentInformation' => [
                    'card' => self::sinVacios([
                        'number' => $cobro->tarjetaDeSandbox['number'] ?? '',
                        'expirationMonth' => $cobro->tarjetaDeSandbox['exp_month'] ?? '',
                        'expirationYear' => $cobro->tarjetaDeSandbox['exp_year'] ?? '',
                        'securityCode' => $cobro->tarjetaDeSandbox['cvv'] ?? '',
                        'type' => $cobro->tarjetaDeSandbox['type'] ?? '',
                    ]),
                ],
            ],
        };

        // Los datos del titular solo hacen falta cuando no hay token que los
        // lleve dentro: con `transientTokenJwt` o con la tarjeta guardada, el
        // billTo vive en la bóveda de Cybersource y repetirlo sobra.
        if ($cobro->facturacion !== []) {
            $cuerpo['orderInformation']['billTo'] = $cobro->facturacion;
        }

        // Cybersource rechaza campos con cadena vacía, así que la IP entra
        // solo si la hay. Un bloque `deviceInformation` con `ipAddress: ""`
        // no es «sin dato»: es un dato inválido.
        if ($cobro->ip !== null && $cobro->ip !== '') {
            $cuerpo['deviceInformation'] = ['ipAddress' => $cobro->ip];
        }

        return $cuerpo;
    }

    /**
     * Cybersource rechaza los campos con cadena vacía en vez de ignorarlos,
     * así que un dato que no tenemos se OMITE, no se manda en blanco.
     *
     * @param  array<string, string>  $campos
     * @return array<string, string>
     */
    private static function sinVacios(array $campos): array
    {
        return array_filter($campos, static fn (string $valor): bool => trim($valor) !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function processingInformation(CobroSolicitado $cobro): array
    {
        $processing = [
            // Venta directa: autorización y captura en el mismo viaje.
            'capture' => true,
            'commerceIndicator' => 'internet',
        ];

        // Las banderas del iniciador se ACUMULAN en una variable y se montan
        // una sola vez al final. Los dos bloques de abajo escriben en la misma
        // clave, y con una asignación por bloque el segundo pisaba el primero:
        // un cobro que tokenizara usando ya un token —guardar una tarjeta
        // nueva en una compra con token— saldría con `storedCredentialUsed` y
        // sin `credentialStoredOnFile`, sin dar error, solo con el cuerpo
        // incompleto. Hoy esa combinación no es alcanzable (el constructor de
        // CobroSolicitado es privado y `conTarjetaGuardada()` fuerza
        // `guardarTarjeta: false`), pero el pisado sería silencioso.
        $initiator = [];

        if ($cobro->guardarTarjeta) {
            // Tokenización: se piden las dos piezas de la bóveda. El
            // `customer` es el que agrupa las tarjetas del asistente; el
            // `paymentInstrument` es la tarjeta concreta.
            $processing['actionList'] = ['TOKEN_CREATE'];
            $processing['actionTokenTypes'] = ['customer', 'paymentInstrument'];

            // La bandera de «primera vez que guardo»: el cliente está
            // delante y autoriza que la credencial quede en archivo. Sin
            // ella los cobros posteriores con el token no tienen de dónde
            // colgarse y la red los puede rechazar.
            $initiator += [
                'type' => 'customer',
                'credentialStoredOnFile' => true,
            ];
        }

        if ($cobro->modo === ModoDeCobro::TarjetaGuardada) {
            // OJO: `type: customer`, no `merchant`. La compra de dos toques
            // la inicia el asistente con el teléfono en la mano — es una CIT
            // con credencial guardada, no una MIT. Marcarla como MIT sería
            // pedir el juego de banderas de «unscheduled credential on file»,
            // que es la ÚNICA pregunta técnica que sigue abierta con
            // PortalDOM (doc 12 §7.1) y que Boletu solo tiene certificada
            // para cuotas (`reason: 9`). Mientras esa respuesta no llegue, no
            // se manda ni `merchantInitiatedTransaction` ni
            // `previousTransactionId`.
            $initiator += [
                'type' => 'customer',
                'storedCredentialUsed' => true,
            ];
        }

        if ($initiator !== []) {
            $processing['authorizationOptions'] = ['initiator' => $initiator];
        }

        return $processing;
    }

    /**
     * La Merchant Defined Data que exige Visanet RD.
     *
     * Las claves son las mismas que manda Boletu en producción, y la 3 cambia
     * de `WEB` a `APP` porque el canal es otro.
     *
     * ─────────────────────────────────────────────────────────────────────
     * LA CLAVE 27 EN EL CASO TOKENIZADO ES UNA DEDUCCIÓN, NO UN DATO
     * CONFIRMADO. SIN CONFIRMAR CON PORTALDOM.
     * ─────────────────────────────────────────────────────────────────────
     * Lo único verificado es el caso NO tokenizado: Boletu manda siempre
     * «TOKENIZATION NO» porque en boletería nunca tokeniza. Que el valor
     * simétrico para el caso tokenizado sea «TOKENIZATION SI» lo dedujimos
     * nosotros; no está confirmado con PortalDOM ni visto en producción, y
     * bien podría ser otra cadena. Que no se lea como verdad establecida.
     *
     * No bloquea el slice porque la MDD es informativa y no decide la
     * autorización —comprobado contra apitest: el cobro aprueba igual—, pero
     * está en la lista de confirmaciones pendientes con PortalDOM.
     *
     * @return list<array<string, string>>
     */
    private function merchantDefinedInformation(CobroSolicitado $cobro): array
    {
        $tokeniza = $cobro->guardarTarjeta || $cobro->modo === ModoDeCobro::TarjetaGuardada;

        return [
            ['key' => '1', 'value' => (string) config('services.portaldom.merchant_category', 'FOOD')],
            ['key' => '2', 'value' => (string) config('services.portaldom.org_id')],
            ['key' => '3', 'value' => (string) config('services.portaldom.channel', 'APP')],
            ['key' => '4', 'value' => $cobro->identificadorDeCliente ?? $cobro->referencia],
            ['key' => '27', 'value' => $tokeniza ? 'TOKENIZATION SI' : 'TOKENIZATION NO'],
        ];
    }

    /**
     * «Cobrado pero sin credencial guardada» es el peor estado posible del
     * sistema: el dinero salió de la cuenta del asistente y no queda nada con
     * que volver a cobrarle ni con que encadenar. Callarlo lo convierte en un
     * fallo que aparece semanas después, en la segunda compra de otra
     * persona.
     *
     * Por eso: log crítico y excepción. Se prefiere una petición que revienta
     * —con el id de transacción a mano para revisar el cobro— a un `200 OK`
     * que miente.
     */
    private function comprobarInvariante(CobroSolicitado $cobro, ResultadoDeCobro $resultado): void
    {
        if (! $resultado->esAprobado()) {
            return;
        }

        if ($cobro->guardarTarjeta && ! $resultado->tieneToken()) {
            Log::critical('[Pagos] cobro APROBADO sin token pese a pedir TOKEN_CREATE', [
                'referencia' => $cobro->referencia,
            ] + $resultado->paraLog());

            throw PaymentsException::aprobadoSinToken($cobro->referencia, $resultado->transactionId);
        }

        if ($resultado->networkTransactionId === null) {
            Log::critical('[Pagos] cobro APROBADO sin networkTransactionId', [
                'referencia' => $cobro->referencia,
            ] + $resultado->paraLog());

            throw PaymentsException::aprobadoSinAnclaDeRed($cobro->referencia, $resultado->transactionId);
        }
    }

    /**
     * El cuerpo listo para el log: sin PAN, sin CVV, sin el JWT entero y sin
     * NINGUNA de las credenciales con las que se puede volver a cobrar.
     *
     * El log es el sitio por donde se escapan los datos de tarjeta sin que
     * nadie lo note: lo leen el despliegue, los respaldos y cualquier
     * agregador. Un PAN completo ahí es un incidente PCI, no una molestia.
     *
     * ─────────────────────────────────────────────────────────────────────
     * LAS RUTAS VAN EN UNA LISTA, NO EN UN `if` POR CAMPO, A PROPÓSITO.
     * ─────────────────────────────────────────────────────────────────────
     * La versión con un `if` por campo cubría `paymentInformation.customer.id`
     * y se olvidaba de `paymentInformation.paymentInstrument.id` dos líneas
     * más abajo, que se escribía ENTERO: uno tapado y el otro en claro, en el
     * mismo objeto. Y `paymentInstrument.id` sirve por sí solo para cobrar en
     * `/pts/v2/payments`. No fue una decisión, fue un olvido — y la forma de
     * que no se repita es que añadir una credencial al cuerpo obligue a
     * añadirla a esta lista, no a acordarse de escribir otro `if`.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private static function redactado(array $cuerpo): array
    {
        // Credenciales: de ellas solo quedan los últimos 4, suficiente para
        // reconciliar con soporte sin dejar la llave escrita.
        $credenciales = [
            ['paymentInformation', 'customer', 'id'],
            ['paymentInformation', 'paymentInstrument', 'id'],
            ['paymentInformation', 'instrumentIdentifier', 'id'],
            ['tokenInformation', 'customer', 'id'],
            ['tokenInformation', 'paymentInstrument', 'id'],
            ['tokenInformation', 'instrumentIdentifier', 'id'],
        ];

        foreach ($credenciales as $ruta) {
            $cuerpo = self::transformar(
                $cuerpo,
                $ruta,
                static fn (string $token): string => '…'.mb_substr($token, -4),
            );
        }

        // El JWT no se trunca: se reduce a su longitud. Sus últimos caracteres
        // son parte de la firma y no identifican nada útil.
        foreach ([['tokenInformation', 'transientTokenJwt'], ['tokenInformation', 'transientToken']] as $ruta) {
            $cuerpo = self::transformar(
                $cuerpo,
                $ruta,
                static fn (string $jwt): string => '(jwt de '.mb_strlen($jwt).' caracteres)',
            );
        }

        // El PAN, enmascarado como manda PCI; el CVV, ni eso: no se guarda
        // NUNCA, ni truncado.
        $cuerpo = self::transformar(
            $cuerpo,
            ['paymentInformation', 'card', 'number'],
            static fn (string $pan): string => 'XXXXXXXXXXXX'.mb_substr($pan, -4),
        );

        foreach ([
            ['paymentInformation', 'card', 'securityCode'],
            ['paymentInformation', 'tokenizedCard', 'cryptogram'],
        ] as $ruta) {
            $cuerpo = self::transformar($cuerpo, $ruta, static fn (string $valor): string => '***');
        }

        return $cuerpo;
    }

    /**
     * Aplica `$como` al valor que hay en `$ruta`, si lo hay. Deja el cuerpo
     * intacto cuando la ruta no existe o el valor no es una cadena.
     *
     * @param  array<string, mixed>  $cuerpo
     * @param  list<string>  $ruta
     * @param  callable(string): string  $como
     * @return array<string, mixed>
     */
    private static function transformar(array $cuerpo, array $ruta, callable $como): array
    {
        $clave = array_shift($ruta);

        if ($clave === null || ! array_key_exists($clave, $cuerpo)) {
            return $cuerpo;
        }

        if ($ruta === []) {
            if (is_string($cuerpo[$clave]) && $cuerpo[$clave] !== '') {
                $cuerpo[$clave] = $como($cuerpo[$clave]);
            }

            return $cuerpo;
        }

        if (is_array($cuerpo[$clave])) {
            /** @var array<string, mixed> $rama */
            $rama = $cuerpo[$clave];
            $cuerpo[$clave] = self::transformar($rama, $ruta, $como);
        }

        return $cuerpo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function comoArray(mixed $modelo): array
    {
        if ($modelo === null) {
            return [];
        }

        if (is_array($modelo)) {
            return $modelo;
        }

        $decodificado = json_decode((string) json_encode(ObjectSerializer::sanitizeForSerialization($modelo)), true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * El cuerpo JSON que trae una ApiException. El SDK lo entrega a veces
     * como string, a veces ya parseado a stdClass, y en un fallo de la capa
     * de red como nada: los tres se normalizan a array.
     *
     * @return array<string, mixed>
     */
    private static function cuerpoDeLaExcepcion(ApiException $e): array
    {
        $crudo = $e->getResponseBody();

        if (is_array($crudo)) {
            return $crudo;
        }

        if (is_object($crudo)) {
            $decodificado = json_decode((string) json_encode($crudo), true);

            return is_array($decodificado) ? $decodificado : ['message' => $e->getMessage()];
        }

        if (is_string($crudo) && $crudo !== '') {
            $decodificado = json_decode($crudo, true);

            return is_array($decodificado) ? $decodificado : ['message' => $crudo];
        }

        return ['message' => $e->getMessage()];
    }
}
