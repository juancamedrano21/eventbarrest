<?php

declare(strict_types=1);

use App\Domains\Payments\Actions\CobrarConTarjeta;
use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\Enums\DesenlaceDeCobro;
use App\Domains\Payments\Enums\ModoDeCobro;
use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\ResultadoDeCobro;
use App\Domains\Payments\Services\CybersourceClient;
use CyberSource\ApiException;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\ObjectSerializer;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    // Valores fijos para que las aserciones no dependan del .env de nadie.
    config()->set('services.portaldom.currency', 'DOP');
    config()->set('services.portaldom.org_id', 'ORG-DE-PRUEBA');
    config()->set('services.portaldom.merchant_category', 'FOOD');
    config()->set('services.portaldom.channel', 'APP');
});

/** La acción, sin red: solo se usa para armar y leer. */
function accionDeCobro(): CobrarConTarjeta
{
    return new CobrarConTarjeta(new CybersourceClient);
}

/**
 * La acción con la ida a Cybersource sustituida por una respuesta enlatada.
 * Prueba todo lo que rodea a la llamada sin credenciales ni conexión.
 *
 * @param  array<string, mixed>  $respuesta
 */
function accionQueResponde(array $respuesta, int $httpStatus = 201): CobrarConTarjeta
{
    return new class(new CybersourceClient, $respuesta, $httpStatus) extends CobrarConTarjeta
    {
        /** @param array<string, mixed> $respuesta */
        public function __construct(
            CybersourceClient $cliente,
            private readonly array $respuesta,
            private readonly int $httpStatus,
        ) {
            parent::__construct($cliente);
        }

        protected function enviar(CobroSolicitado $cobro, array $cuerpo): array
        {
            return [$this->respuesta, $this->httpStatus];
        }
    };
}

/**
 * La acción cuya ida a la red revienta con la excepción que lanza el SDK DE
 * VERDAD. Se sustituye solo el cable: quien clasifica la excepción sigue
 * siendo el código de producción, que es lo que hay que probar.
 */
function accionQueLanza(Throwable $excepcion): CobrarConTarjeta
{
    return new class(new CybersourceClient, $excepcion) extends CobrarConTarjeta
    {
        public function __construct(CybersourceClient $cliente, private readonly Throwable $excepcion)
        {
            parent::__construct($cliente);
        }

        protected function enviar(CobroSolicitado $cobro, array $cuerpo): array
        {
            throw $this->excepcion;
        }
    };
}

/** El cuerpo del log de la petición, que es donde se escapan las credenciales. */
function cuerpoRegistrado(CobrarConTarjeta $accion, CobroSolicitado $cobro): string
{
    $lineas = [];

    Log::listen(function (MessageLogged $mensaje) use (&$lineas): void {
        // Sin escapar el unicode: la huella de un token truncado empieza por
        // «…», y con `…` la comparación de este test miraría otra cosa.
        $lineas[] = $mensaje->message.' '.json_encode($mensaje->context, JSON_UNESCAPED_UNICODE);
    });

    $accion($cobro);

    return implode("\n", $lineas);
}

// ── El cuerpo que se manda ──────────────────────────────────────────────

it('builds the first charge as a sale that also tokenises the card', function (): void {
    $cuerpo = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaNueva('PED-0001', 125_050, 'el.jwt.transitorio', 'llave-1', 'cuenta-42')
    );

    expect($cuerpo['clientReferenceInformation']['code'])->toBe('PED-0001')
        ->and($cuerpo['processingInformation']['capture'])->toBeTrue()
        ->and($cuerpo['processingInformation']['commerceIndicator'])->toBe('internet')
        ->and($cuerpo['processingInformation']['actionList'])->toBe(['TOKEN_CREATE'])
        ->and($cuerpo['processingInformation']['actionTokenTypes'])->toBe(['customer', 'paymentInstrument'])
        ->and($cuerpo['processingInformation']['authorizationOptions']['initiator'])->toBe([
            'type' => 'customer',
            'credentialStoredOnFile' => true,
        ])
        // El JWT entero, y bajo `transientTokenJwt`: con `jti` o con
        // `transientToken` Cybersource contesta INVALID_DATA.
        ->and($cuerpo['tokenInformation'])->toBe(['transientTokenJwt' => 'el.jwt.transitorio'])
        ->and($cuerpo)->not->toHaveKey('paymentInformation');
});

it('sends the amount as a two-decimal string in the configured currency', function (): void {
    $cuerpo = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaNueva('PED-0002', 125_050, 'jwt', 'llave-2')
    );

    expect($cuerpo['orderInformation']['amountDetails'])->toBe([
        'totalAmount' => '1250.50',
        'currency' => 'DOP',
    ]);
});

it('builds the two-tap charge with the stored token and no card data', function (): void {
    $cuerpo = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaGuardada('PED-0003', 30_000, 'CUSTOMER-TOKEN', 'llave-3')
    );

    expect($cuerpo['paymentInformation'])->toBe(['customer' => ['id' => 'CUSTOMER-TOKEN']])
        // Ni tokenización nueva ni datos de tarjeta: solo la credencial.
        ->and($cuerpo['processingInformation'])->not->toHaveKey('actionList')
        ->and($cuerpo['processingInformation']['authorizationOptions']['initiator'])->toBe([
            'type' => 'customer',
            'storedCredentialUsed' => true,
        ])
        ->and($cuerpo)->not->toHaveKey('tokenInformation');
});

it('adds the payment instrument only when there is one', function (): void {
    $sinInstrumento = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaGuardada('PED-0004', 30_000, 'CUSTOMER-TOKEN', 'llave-4')
    );
    $conInstrumento = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaGuardada('PED-0005', 30_000, 'CUSTOMER-TOKEN', 'llave-5', 'INSTRUMENT-TOKEN')
    );

    expect($sinInstrumento['paymentInformation'])->not->toHaveKey('paymentInstrument')
        ->and($conInstrumento['paymentInformation']['paymentInstrument'])->toBe(['id' => 'INSTRUMENT-TOKEN']);
});

it('merges the initiator flags instead of overwriting them', function (): void {
    // Esta combinación —guardar una tarjeta nueva en un cobro que YA usa
    // token— no es alcanzable hoy: el constructor es privado y
    // `conTarjetaGuardada()` fuerza `guardarTarjeta: false`. Se fabrica por
    // reflexión a propósito, porque el fallo que se fija es un pisado
    // SILENCIOSO: los dos bloques escriben en `authorizationOptions.initiator`
    // y una asignación en vez de una fusión perdería `credentialStoredOnFile`
    // sin dar error, solo un cuerpo incompleto el día que ese flujo aparezca.
    $reflexion = new ReflectionClass(CobroSolicitado::class);
    /** @var CobroSolicitado $cobro */
    $cobro = $reflexion->newInstanceWithoutConstructor();
    $reflexion->getConstructor()?->invoke(
        $cobro,
        ModoDeCobro::TarjetaGuardada,
        'PED-0017',
        30_000,
        'llave-17',
        true,
        null,
        'CUSTOMER-TOKEN',
    );

    $initiator = accionDeCobro()->cuerpo($cobro)['processingInformation']['authorizationOptions']['initiator'];

    expect($initiator)->toBe([
        'type' => 'customer',
        'credentialStoredOnFile' => true,
        'storedCredentialUsed' => true,
    ]);
});

it('omits fields cybersource rejects when empty', function (): void {
    // Un `ipAddress: ""` no es «sin dato» para Cybersource: es un dato
    // inválido, y tumba la petición entera.
    $sinIp = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaNueva('PED-0006', 1_000, 'jwt', 'llave-6')
    );
    $conIp = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaNueva('PED-0007', 1_000, 'jwt', 'llave-7', null, '190.80.1.1')
    );

    expect($sinIp)->not->toHaveKey('deviceInformation')
        ->and($conIp['deviceInformation'])->toBe(['ipAddress' => '190.80.1.1']);

    $tarjetaSinCvv = accionDeCobro()->cuerpo(
        CobroSolicitado::conPanDeSandbox('PED-0008', 1_000, [
            'number' => '4111111111111111',
            'exp_month' => '12',
            'exp_year' => '2031',
        ], 'llave-8')
    );

    expect($tarjetaSinCvv['paymentInformation']['card'])->toBe([
        'number' => '4111111111111111',
        'expirationMonth' => '12',
        'expirationYear' => '2031',
    ]);
});

it('fills the merchant defined data visanet rd expects', function (): void {
    $cuerpo = accionDeCobro()->cuerpo(
        CobroSolicitado::conTarjetaNueva('PED-0009', 1_000, 'jwt', 'llave-9', 'cuenta-42')
    );

    expect($cuerpo['merchantDefinedInformation'])->toBe([
        ['key' => '1', 'value' => 'FOOD'],
        ['key' => '2', 'value' => 'ORG-DE-PRUEBA'],
        ['key' => '3', 'value' => 'APP'],
        ['key' => '4', 'value' => 'cuenta-42'],
        ['key' => '27', 'value' => 'TOKENIZATION SI'],
    ]);
});

it('survives the sdk serialiser without losing a field', function (): void {
    // El serializador del SDK solo copia las claves que el modelo declara:
    // una mal escrita no da error, DESAPARECE. Este test compara lo que
    // armamos contra lo que de verdad saldría por el cable.
    $cobro = CobroSolicitado::conTarjetaNueva('PED-0010', 125_050, 'el.jwt', 'llave-10', 'cuenta-42', '190.80.1.1');
    $cuerpo = accionDeCobro()->cuerpo($cobro);

    $modelo = app(CybersourceClient::class)->sdkModel($cuerpo, CreatePaymentRequest::class);
    $serializado = json_decode((string) json_encode(ObjectSerializer::sanitizeForSerialization($modelo)), true);

    expect($serializado['clientReferenceInformation']['code'])->toBe('PED-0010')
        ->and($serializado['processingInformation']['capture'])->toBeTrue()
        ->and($serializado['processingInformation']['actionList'])->toBe(['TOKEN_CREATE'])
        ->and($serializado['processingInformation']['actionTokenTypes'])->toBe(['customer', 'paymentInstrument'])
        ->and($serializado['processingInformation']['authorizationOptions']['initiator']['credentialStoredOnFile'])->toBeTrue()
        ->and($serializado['orderInformation']['amountDetails']['totalAmount'])->toBe('1250.50')
        ->and($serializado['tokenInformation']['transientTokenJwt'])->toBe('el.jwt')
        ->and($serializado['deviceInformation']['ipAddress'])->toBe('190.80.1.1')
        ->and($serializado['merchantDefinedInformation'])->toHaveCount(5);
});

// ── La lectura de la respuesta ──────────────────────────────────────────

it('reads an approved charge through the action', function (): void {
    $resultado = accionQueResponde([
        'id' => '7712345',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => '0161535'],
        'tokenInformation' => [
            'customer' => ['id' => 'CUSTOMER-TOKEN'],
            'paymentInstrument' => ['id' => 'INSTRUMENT-TOKEN'],
        ],
    ])(CobroSolicitado::conTarjetaNueva('PED-0011', 1_000, 'jwt', 'llave-11'));

    expect($resultado)->toBeInstanceOf(ResultadoDeCobro::class)
        ->and($resultado->esAprobado())->toBeTrue()
        ->and($resultado->customerTokenId)->toBe('CUSTOMER-TOKEN')
        ->and($resultado->paymentInstrumentId)->toBe('INSTRUMENT-TOKEN')
        ->and($resultado->networkTransactionId)->toBe('0161535');
});

it('does not raise the invariant on a charge that was never approved', function (): void {
    // Sin token y sin networkTransactionId, pero tampoco hubo cobro: no hay
    // nada roto que denunciar, solo un rechazo normal.
    $resultado = accionQueResponde(['id' => '77999', 'status' => 'DECLINED'], 201)(
        CobroSolicitado::conTarjetaNueva('PED-0012', 1_000, 'jwt', 'llave-12')
    );

    expect($resultado->esRechazado())->toBeTrue();
});

// ── La guarda de invariante ─────────────────────────────────────────────

it('fails loudly when an approved charge comes back without the token it asked for', function (): void {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('critical')->once()->withArgs(
        fn (string $mensaje, array $contexto): bool => str_contains($mensaje, 'sin token')
            && $contexto['referencia'] === 'PED-0013'
    );

    $accion = accionQueResponde([
        'id' => '7712345',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => '0161535'],
        // Se pidió TOKEN_CREATE y no volvió nada: cobrado sin credencial.
    ]);

    $accion(CobroSolicitado::conTarjetaNueva('PED-0013', 1_000, 'jwt', 'llave-13'));
})->throws(PaymentsException::class, 'no devolvió token');

it('fails loudly when an approved charge comes back without the network anchor', function (): void {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('critical')->once();

    $accion = accionQueResponde([
        'id' => '7712345',
        'status' => 'AUTHORIZED',
        'tokenInformation' => [
            'customer' => ['id' => 'CUSTOMER-TOKEN'],
            'paymentInstrument' => ['id' => 'INSTRUMENT-TOKEN'],
        ],
        // Sin processorInformation.networkTransactionId no hay ancla para
        // encadenar los cobros siguientes.
    ]);

    $accion(CobroSolicitado::conTarjetaNueva('PED-0014', 1_000, 'jwt', 'llave-14'));
})->throws(PaymentsException::class, 'networkTransactionId');

it('does not demand a token from a charge that never asked to save the card', function (): void {
    $resultado = accionQueResponde([
        'id' => '7712345',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => '0161535'],
    ])(CobroSolicitado::conTarjetaGuardada('PED-0015', 1_000, 'CUSTOMER-TOKEN', 'llave-15'));

    expect($resultado->esAprobado())->toBeTrue()
        ->and($resultado->tieneToken())->toBeFalse();
});

// ── «Me dijeron que no» NO es «no sé si se cobró» ───────────────────────

it('does not turn a cut connection into a decline', function (): void {
    // Lo que lanza el SDK cuando curl no completa la llamada: ApiException
    // con código 0, sin cabeceras y sin cuerpo (ApiClient::callApi(), rama
    // `http_code === 0`). Antes caía en el mismo catch que un 400 y salía
    // como un resultado ordinario: indistinguible de un rechazo.
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once()->withArgs(
        fn (string $mensaje, array $contexto): bool => str_contains($mensaje, 'PUEDE HABERSE COBRADO')
            && $contexto['referencia'] === 'PED-0100'
    );

    $resultado = accionQueLanza(new ApiException(
        'API call to https://apitest.cybersource.com/pts/v2/payments failed: Operation timed out', 0, [], null
    ))(CobroSolicitado::conTarjetaNueva('PED-0100', 1_000, 'jwt', 'llave-100'));

    expect($resultado->desenlace())->toBe(DesenlaceDeCobro::Incierto)
        ->and($resultado->esIncierto())->toBeTrue()
        // Y las tres preguntas que deciden algo contestan que no: nadie
        // despacha, nadie lo cuenta como rechazo y nadie reintenta a ciegas.
        ->and($resultado->esAprobado())->toBeFalse()
        ->and($resultado->esRechazado())->toBeFalse()
        ->and($resultado->esPendiente())->toBeFalse()
        ->and($resultado->httpStatus)->toBe(0);
});

it('treats a 5xx without a payment decision as uncertain too', function (): void {
    // Un 502 o un 504 pueden llegar DESPUÉS de que el emisor autorizara. Que
    // haya código HTTP no significa que haya decisión de pago.
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    $resultado = accionQueLanza(new ApiException('Bad gateway', 502, [], '{"message":"upstream error"}'))(
        CobroSolicitado::conTarjetaNueva('PED-0101', 1_000, 'jwt', 'llave-101')
    );

    expect($resultado->esIncierto())->toBeTrue()
        ->and($resultado->esRechazado())->toBeFalse();
});

it('still reads a 4xx with a body as the answer it is', function (): void {
    // Esto es lo que NO se puede romper arreglando lo de arriba: un 400 de
    // Cybersource SÍ es una respuesta, trae `status` y motivo, y ahí sí se
    // sabe que no se cobró. El árbitro sigue siendo `body.status`.
    $resultado = accionQueLanza(new ApiException('Bad request', 400, [], json_encode([
        'status' => 'INVALID_REQUEST',
        'reason' => 'MISSING_FIELD',
        'message' => 'Declined - The request is missing one or more fields',
    ])))(CobroSolicitado::conTarjetaNueva('PED-0102', 1_000, 'jwt', 'llave-102'));

    expect($resultado->esIncierto())->toBeFalse()
        ->and($resultado->desenlace())->toBe(DesenlaceDeCobro::Error)
        ->and($resultado->httpStatus)->toBe(400)
        ->and($resultado->motivo)->toBe('MISSING_FIELD');
});

it('treats a 2xx whose body arrived half-written as uncertain, not as an error', function (): void {
    // El caso que un refutador provocó cortando la respuesta a mitad de un
    // 201 (curl exit 18). Es el MÁS peligroso de todos: Cybersource ya creó
    // la transacción —la tarjeta puede estar cobrada— y el cuerpo llega sin
    // `status`. Antes caía en `desdeRespuesta()`, que sin `status` clasifica
    // `Desconocido` → `error`: el camino callado, el que invita a reintentar
    // y a cobrar dos veces. Ahora es el mismo silencio que un corte, porque
    // es exactamente el mismo silencio.
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once()->withArgs(
        fn (string $mensaje, array $contexto): bool => str_contains($mensaje, 'PUEDE HABERSE COBRADO')
            && $contexto['http_status'] === 201
    );

    $resultado = accionQueResponde(['id' => '7861318426266202103814'], 201)(
        CobroSolicitado::conTarjetaNueva('PED-0104', 1_000, 'jwt', 'llave-104')
    );

    expect($resultado->esIncierto())->toBeTrue()
        ->and($resultado->desenlace())->toBe(DesenlaceDeCobro::Incierto)
        ->and($resultado->esAprobado())->toBeFalse()
        ->and($resultado->esRechazado())->toBeFalse();
});

it('fails loudly when the failure is not even a transport one', function (): void {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    accionQueLanza(new RuntimeException('el modelo del sdk no se pudo armar'))(
        CobroSolicitado::conTarjetaNueva('PED-0103', 1_000, 'jwt', 'llave-103')
    );
})->throws(PaymentsException::class, 'no se completó');

// ── Ninguna credencial en el log ────────────────────────────────────────

it('never writes a whole credential to the log', function (): void {
    // El defecto que esto cierra: `paymentInstrument.id` se escribía ENTERO
    // mientras su hermano `customer.id` sí se truncaba, en el mismo objeto.
    // Es una credencial con la que se puede cobrar.
    $registrado = cuerpoRegistrado(
        accionQueResponde(['id' => '77123', 'status' => 'AUTHORIZED', 'processorInformation' => ['networkTransactionId' => '0161535']]),
        CobroSolicitado::conTarjetaGuardada('PED-0104', 1_000, 'CUSTOMER-TOKEN-2222', 'llave-104', 'PI9F8E7D6C5B4A3928374655AAAA0CE2')
    );

    expect($registrado)->not->toContain('CUSTOMER-TOKEN-2222')
        ->not->toContain('PI9F8E7D6C5B4A3928374655AAAA0CE2')
        // Y de las dos queda la misma huella: cuatro caracteres para
        // reconciliar con soporte, ni uno más.
        ->toContain('…2222')
        ->toContain('…0CE2');
});

it('never writes a pan, a cvv or a whole jwt to the log', function (): void {
    config()->set('services.portaldom.api_host', 'apitest.cybersource.com');

    $conPan = cuerpoRegistrado(
        accionQueResponde(['id' => '77124', 'status' => 'AUTHORIZED', 'processorInformation' => ['networkTransactionId' => '0161535']]),
        CobroSolicitado::conPanDeSandbox('PED-0105', 1_000, [
            'number' => '4111111111111111',
            'exp_month' => '12',
            'exp_year' => '2031',
            'cvv' => '123',
        ], 'llave-105')
    );

    expect($conPan)->not->toContain('4111111111111111')
        ->toContain('XXXXXXXXXXXX1111')
        ->toContain('***');

    $jwt = 'eyJhbGciOiJSUzI1NiJ9.el-cuerpo-del-token-transitorio.la-firma-entera';

    $conJwt = cuerpoRegistrado(
        accionQueResponde([
            'id' => '77125',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['networkTransactionId' => '0161535'],
            'tokenInformation' => ['customer' => ['id' => 'C-1'], 'paymentInstrument' => ['id' => 'PI-1']],
        ]),
        CobroSolicitado::conTarjetaNueva('PED-0106', 1_000, $jwt, 'llave-106')
    );

    expect($conJwt)->not->toContain($jwt)
        ->not->toContain('la-firma-entera')
        ->toContain('(jwt de '.mb_strlen($jwt).' caracteres)');
});

it('keeps no secret of the payments domain out of its own reach', function (): void {
    // El barrido: TODO lo que el dominio escribe en un cobro pasa por aquí, y
    // ninguno de los secretos sale entero. Si mañana alguien añade una
    // credencial al cuerpo y se olvida de `redactado()`, este test cae.
    config()->set('services.portaldom.shared_secret', 'ZWxTZWNyZXRvQ29tcGFydGlkb0RlUG9ydGFsRE9N');
    config()->set('services.portaldom.key_id', 'la-llave-de-portaldom');

    $registrado = cuerpoRegistrado(
        accionQueResponde([
            'id' => '77126',
            'status' => 'AUTHORIZED',
            'processorInformation' => ['networkTransactionId' => '0161535'],
            'tokenInformation' => [
                'customer' => ['id' => 'CUSTOMER-TOKEN-RESPUESTA'],
                'paymentInstrument' => ['id' => 'INSTRUMENT-TOKEN-RESPUESTA'],
                'instrumentIdentifier' => ['id' => 'IDENTIFIER-TOKEN-RESPUESTA'],
            ],
        ]),
        CobroSolicitado::conTarjetaGuardada('PED-0107', 1_000, 'CUSTOMER-TOKEN-PETICION', 'llave-107', 'INSTRUMENT-TOKEN-PETICION')
    );

    foreach ([
        'CUSTOMER-TOKEN-PETICION',
        'INSTRUMENT-TOKEN-PETICION',
        'CUSTOMER-TOKEN-RESPUESTA',
        'INSTRUMENT-TOKEN-RESPUESTA',
        'IDENTIFIER-TOKEN-RESPUESTA',
        'ZWxTZWNyZXRvQ29tcGFydGlkb0RlUG9ydGFsRE9N',
        'la-llave-de-portaldom',
    ] as $secreto) {
        expect($registrado)->not->toContain($secreto);
    }
});

// ── La credencial completa, o no hay credencial ─────────────────────────

it('does not accept half a stored credential as a saved card', function (): void {
    // Se piden las dos piezas (`actionTokenTypes: [customer, paymentInstrument]`)
    // y con una sola la tarjeta guardada no sirve: el fallo reaparecería al
    // intentar cobrar la tarjeta concreta, semanas después.
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('critical')->once();

    accionQueResponde([
        'id' => '7712345',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => '0161535'],
        'tokenInformation' => ['customer' => ['id' => 'CUSTOMER-TOKEN']],
    ])(CobroSolicitado::conTarjetaNueva('PED-0108', 1_000, 'jwt', 'llave-108'));
})->throws(PaymentsException::class, 'no devolvió token');

// ── El motivo del rechazo, donde de verdad vive ─────────────────────────

it('reads the decline reason from errorInformation and not from the root', function (): void {
    // Cuerpo LITERAL de apitest con el PAN 4111111111111112 (2026-08-07).
    // Sin `reason` ni `message` en la raíz: leyendo solo la raíz, el motivo
    // salía NULL en todos los rechazos reales y la app no podía decirle al
    // asistente por qué le rechazaron la tarjeta.
    $resultado = accionQueResponde([
        'id' => '7861327413326147703812',
        'submitTimeUtc' => '2026-08-07T19:59:01Z',
        'status' => 'DECLINED',
        'errorInformation' => [
            'reason' => 'INVALID_ACCOUNT',
            'message' => 'Decline - Invalid account number',
        ],
        'clientReferenceInformation' => ['code' => 'PED-0109'],
    ])(CobroSolicitado::conTarjetaNueva('PED-0109', 1_000, 'jwt', 'llave-109'));

    expect($resultado->esRechazado())->toBeTrue()
        ->and($resultado->motivo)->toBe('INVALID_ACCOUNT')
        ->and($resultado->mensaje)->toBe('Decline - Invalid account number')
        ->and($resultado->paraLog()['motivo'])->toBe('INVALID_ACCOUNT');
});

// ── Los seguros de entorno ──────────────────────────────────────────────

it('refuses to boot the client with live credentials outside production', function (): void {
    // La segunda puerta del seguro: existe porque con `config:cache` el
    // fichero de configuración —donde está la primera— no se ejecuta.
    config()->set('services.portaldom.env', 'live');

    new CybersourceClient;
})->throws(PaymentsException::class, 'PORTALDOM_ENV=live');

it('refuses to boot when the label says test but the host charges for real', function (): void {
    // El agujero que esto cierra: `PORTALDOM_ENV` es una etiqueta, y la
    // variable que decide a dónde va el dinero es `PORTALDOM_API_HOST`
    // (`ApiClient` arma la URL con `Configuration::getHost()`). Esta
    // combinación es escribible en un .env y con ella los seguros que solo
    // miran la etiqueta daban luz verde a cobros contra producción.
    config()->set('services.portaldom.env', 'test');
    config()->set('services.portaldom.api_host', 'api.cybersource.com');

    new CybersourceClient;
})->throws(PaymentsException::class, 'PORTALDOM_API_HOST=api.cybersource.com');

it('refuses to boot when the label and the host contradict each other', function (): void {
    // Al revés también miente: un `live` que apunta a apitest no cobra nada,
    // y nadie se entera hasta que falta el dinero.
    config()->set('app.env', 'production');
    config()->set('services.portaldom.env', 'live');
    config()->set('services.portaldom.api_host', 'apitest.cybersource.com');

    new CybersourceClient;
})->throws(PaymentsException::class, 'se contradicen');

it('answers whether it is the sandbox with the host, not with the label', function (): void {
    config()->set('app.env', 'production');
    config()->set('services.portaldom.env', 'live');
    config()->set('services.portaldom.api_host', 'api.cybersource.com');

    expect((new CybersourceClient)->esSandbox())->toBeFalse();

    config()->set('app.env', 'testing');
    config()->set('services.portaldom.env', 'test');
    config()->set('services.portaldom.api_host', 'apitest.cybersource.com');

    expect((new CybersourceClient)->esSandbox())->toBeTrue();
});

it('refuses to send a raw pan anywhere but the sandbox', function (): void {
    // Con el host de producción este camino no existe: un PAN en este
    // servidor es alcance SAQ D, justo lo que el diseño de captura evita.
    config()->set('app.env', 'production');
    config()->set('services.portaldom.env', 'live');
    config()->set('services.portaldom.api_host', 'api.cybersource.com');

    $cobro = CobroSolicitado::conPanDeSandbox('PED-0016', 1_000, [
        'number' => '4111111111111111',
        'exp_month' => '12',
        'exp_year' => '2031',
    ], 'llave-16');

    accionDeCobro()($cobro);
})->throws(PaymentsException::class, 'sandbox');
