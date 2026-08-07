<?php

declare(strict_types=1);

use App\Domains\Payments\Actions\BuscarCobroPorReferencia;
use App\Domains\Payments\Actions\CobrarConTarjeta;
use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\ConciliacionDeCobro;
use App\Domains\Payments\Enums\DesenlaceDeCobro;
use App\Domains\Payments\ResultadoDeCobro;
use App\Domains\Payments\Services\CybersourceClient;
use CyberSource\Api\PaymentsApi;
use CyberSource\ApiException;
use CyberSource\Model\CreatePaymentRequest;
use Illuminate\Support\Str;

/**
 * Pruebas contra el SANDBOX REAL de Cybersource (apitest.cybersource.com).
 *
 * SE SALTAN SOLAS sin credenciales. No es cortesía: una suite que exige las
 * credenciales de un integrador de pagos es una suite que no puede correr
 * nadie más —ni CI, ni un clon recién hecho, ni el que solo viene a tocar el
 * KDS—. Con `PORTALDOM_*` puestas se ejecutan; sin ellas, se saltan y la
 * suite sigue verde.
 *
 * Se saltan TAMBIÉN si el entorno no es el de pruebas: estas pruebas cobran
 * de verdad, y contra `live` eso es dinero de alguien.
 *
 * Grupo `cybersource` para poder aislarlas: `pest --group=cybersource`.
 */
beforeEach(function (): void {
    if (! CybersourceClient::hayCredenciales()) {
        $this->markTestSkipped('Sin credenciales de PortalDOM: define PORTALDOM_ORG_ID, PORTALDOM_KEY_ID y PORTALDOM_SHARED_SECRET.');
    }

    if (! app(CybersourceClient::class)->esSandbox()) {
        $this->markTestSkipped('PORTALDOM_ENV no es de pruebas: estas llamadas cobran de verdad.');
    }
});

/** La Visa de prueba que documenta Cybersource para el sandbox. */
function tarjetaDePrueba(): array
{
    return [
        'number' => '4111111111111111',
        'exp_month' => '12',
        'exp_year' => '2031',
        'cvv' => '123',
        'type' => '001',
    ];
}

/** Un titular cualquiera: sin token que lo lleve dentro, hay que mandarlo. */
function facturacionDePrueba(): array
{
    return [
        'firstName' => 'Juan',
        'lastName' => 'Perez',
        'address1' => 'Av. John F. Kennedy 1',
        'locality' => 'Santo Domingo',
        'administrativeArea' => 'Distrito Nacional',
        'postalCode' => '10100',
        'country' => 'DO',
        'email' => 'pruebas@eventbarrest.test',
        'phoneNumber' => '8095550100',
    ];
}

function referenciaDePrueba(): string
{
    return 'EBR-TEST-'.Str::upper(Str::random(10));
}

test('the signature authenticates against the live sandbox', function (): void {
    // Una llamada deliberadamente incompleta: si la firma NO valida,
    // Cybersource contesta 401 antes de mirar el cuerpo. Cualquier otra cosa
    // —un 400 por campos que faltan— demuestra que autenticamos.
    $cliente = app(CybersourceClient::class);
    $api = new PaymentsApi($cliente->apiClient());

    $codigo = 0;
    $cuerpo = null;

    try {
        $api->createPaymentWithHttpInfo(
            $cliente->sdkModel(['clientReferenceInformation' => ['code' => referenciaDePrueba()]], CreatePaymentRequest::class)
        );
        $codigo = 201;
    } catch (ApiException $e) {
        $codigo = $e->getCode();
        $cuerpo = $e->getResponseBody();
    }

    expect($codigo)->not->toBe(401)
        ->and($cliente->host())->toBe('apitest.cybersource.com');

    // Se deja constancia de lo que devolvió, que es lo que se reporta.
    fwrite(STDERR, "\n[sandbox] auth viva → HTTP {$codigo} · ".json_encode($cuerpo)."\n");
})->group('cybersource');

test('a visa test pan is authorized in DOP and returns a network transaction id', function (): void {
    $referencia = referenciaDePrueba();

    $resultado = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: $referencia,
            importeCents: 25_000, // 250.00 DOP
            tarjeta: tarjetaDePrueba(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionDePrueba(),
        )
    );

    fwrite(STDERR, "\n[sandbox] cobro simple → ".json_encode($resultado->paraLog())."\n");

    expect($resultado->esAprobado())->toBeTrue()
        ->and($resultado->estado->value)->toBe('AUTHORIZED')
        ->and($resultado->transactionId)->not->toBeNull()
        // El ancla del encadenado. Sin ella la acción ya habría reventado.
        ->and($resultado->networkTransactionId)->not->toBeNull();
})->group('cybersource');

test('asking for TOKEN_CREATE on an approved charge returns a customer token', function (): void {
    $resultado = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: referenciaDePrueba(),
            importeCents: 25_000,
            tarjeta: tarjetaDePrueba(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionDePrueba(),
            guardarTarjeta: true,
        )
    );

    fwrite(STDERR, "\n[sandbox] cobro + TOKEN_CREATE → ".json_encode($resultado->paraLog())."\n");

    expect($resultado->esAprobado())->toBeTrue()
        ->and($resultado->customerTokenId)->not->toBeNull()
        ->and($resultado->paymentInstrumentId)->not->toBeNull();
})->group('cybersource');

test('a second charge goes through with the saved token and no card data', function (): void {
    // Primero el alta con compra, que es de donde sale la credencial.
    $alta = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: referenciaDePrueba(),
            importeCents: 25_000,
            tarjeta: tarjetaDePrueba(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionDePrueba(),
            guardarTarjeta: true,
        )
    );

    expect($alta->customerTokenId)->not->toBeNull();

    // Y ahora la compra de dos toques: solo el token.
    $accion = app(CobrarConTarjeta::class);
    $cobro = CobroSolicitado::conTarjetaGuardada(
        referencia: referenciaDePrueba(),
        importeCents: 15_000,
        customerTokenId: (string) $alta->customerTokenId,
        idempotencyKey: (string) Str::uuid(),
    );

    // Que de verdad no viaja ningún dato de tarjeta.
    $cuerpo = $accion->cuerpo($cobro);
    expect($cuerpo['paymentInformation'])->not->toHaveKey('card');

    $segundo = $accion($cobro);

    fwrite(STDERR, "\n[sandbox] cobro con token guardado → ".json_encode($segundo->paraLog())."\n");

    expect($segundo->esAprobado())->toBeTrue()
        ->and($segundo->networkTransactionId)->not->toBeNull();
})->group('cybersource');

test('the idempotency key rides on the payment call and never sticks to the shared client', function (): void {
    // Lo que sí depende de nosotros, y es lo que más daño hace si se rompe:
    // la cabecera va en el cliente EFÍMERO del cobro y NO queda pegada al
    // compartido. Pegada, la segunda compra del asistente llegaría con la
    // llave de la primera.
    $cliente = app(CybersourceClient::class);

    $conLlave = $cliente->apiClientWithIdempotency('11111111-2222-3333-4444-555555555555');
    $compartido = $cliente->apiClient();

    expect($conLlave->getConfig()->getDefaultHeaders())
        ->toBe(['v-c-idempotency-id' => '11111111-2222-3333-4444-555555555555'])
        ->and($compartido->getConfig()->getDefaultHeaders())->toBe([])
        ->and($conLlave)->not->toBe($compartido)
        // Y los dos apuntan al mismo host, que es el fallo del doble objeto.
        ->and($conLlave->getConfig()->getHost())->toBe($cliente->merchantConfig()->getRunEnvironment())
        ->and($compartido->getConfig()->getHost())->toBe($cliente->merchantConfig()->getRunEnvironment());
})->group('cybersource');

test('a charge can be found again by our own reference', function (): void {
    // EL CAMINO DE RECONCILIACIÓN del doc 12 §4, contra el sandbox de verdad.
    // Es lo que hay que hacer antes de reintentar un cobro `incierto`: si la
    // llamada se cortó, preguntar si la referencia ya existe. Y hoy no es un
    // colchón sino la única defensa, porque este MID no honra la idempotencia.
    $referencia = referenciaDePrueba();

    $cobro = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: $referencia,
            importeCents: 12_300,
            tarjeta: tarjetaDePrueba(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionDePrueba(),
        )
    );

    expect($cobro->esAprobado())->toBeTrue();

    $buscar = app(BuscarCobroPorReferencia::class);

    // El retraso de indexado es real y medido: a 0,3 s la búsqueda devolvía 0
    // y a 4,6 s ya devolvía la transacción. Se sondea hasta el techo que fija
    // ConciliacionDeCobro, que es lo mismo que hará el llamador.
    $conciliacion = $buscar($referencia);
    $inmediato = $conciliacion->hayRastro();

    for ($esperado = 0; ! $conciliacion->hayRastro() && $esperado < ConciliacionDeCobro::SEGUNDOS_DE_INDEXADO; $esperado += 3) {
        sleep(3);
        $conciliacion = $buscar($referencia);
    }

    fwrite(STDERR, "\n[sandbox] busqueda por referencia → inmediata: ".($inmediato ? 'si' : 'no')
        ." · tras ~{$esperado}s: ".json_encode($conciliacion->paraLog())."\n");

    expect($conciliacion->hayRastro())->toBeTrue()
        ->and($conciliacion->cobroAprobado())->not->toBeNull()
        // El id que devolvió el cobro es el que encuentra la búsqueda: sin eso
        // la conciliación no ata nada.
        ->and($conciliacion->cobroAprobado()?->transactionId)->toBe($cobro->transactionId)
        ->and($conciliacion->cobroAprobado()?->referencia)->toBe($referencia)
        ->and($conciliacion->cobroAprobado()?->importe)->toBe('123.00')
        // Y con rastro NO se reintenta, por mucho tiempo que pase.
        ->and($conciliacion->sePuedeReintentar(3_600))->toBeFalse();
})->group('cybersource');

test('a reference that was never charged comes back empty instead of failing', function (): void {
    // La otra mitad, y la que decide un reintento: una referencia inexistente
    // tiene que dar «no hay rastro» limpio. Si el MID no tuviera habilitada la
    // búsqueda, esto reventaría con `busqueda_no_disponible` en vez de mentir
    // con un cero — que es la diferencia entre «no existe» y «no pude mirar».
    $conciliacion = app(BuscarCobroPorReferencia::class)(referenciaDePrueba().'-NUNCA');

    fwrite(STDERR, "\n[sandbox] busqueda de una referencia inexistente → ".json_encode($conciliacion->paraLog())."\n");

    expect($conciliacion->hayRastro())->toBeFalse()
        ->and($conciliacion->total)->toBe(0)
        ->and($conciliacion->sePuedeReintentar(ConciliacionDeCobro::SEGUNDOS_DE_INDEXADO))->toBeTrue();
})->group('cybersource');

test('a declined charge says why, and the reason survives to the log', function (): void {
    // El PAN inválido es el único rechazo que este MID produce (los importes
    // y la caducidad salen todos AUTHORIZED). Sirve para fijar que el motivo
    // se lee de `errorInformation` y no de la raíz, contra el cuerpo real.
    $tarjeta = tarjetaDePrueba();
    $tarjeta['number'] = '4111111111111112';

    $resultado = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: referenciaDePrueba(),
            importeCents: 12_300,
            tarjeta: $tarjeta,
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionDePrueba(),
        )
    );

    fwrite(STDERR, "\n[sandbox] rechazo con motivo → ".json_encode($resultado->paraLog())."\n");

    expect($resultado->esRechazado())->toBeTrue()
        ->and($resultado->esIncierto())->toBeFalse()
        ->and($resultado->motivo)->not->toBeNull()
        ->and($resultado->mensaje)->not->toBeNull();
})->group('cybersource');

test('a charge that never reaches cybersource is uncertain, not declined', function (): void {
    // El corte de verdad, con curl fallando de verdad: se apunta a un host de
    // pruebas que no resuelve. El SDK lanza ApiException con código 0 y sin
    // cuerpo, exactamente igual que en un timeout de festival, y lo que hay
    // que comprobar es que NO sale por el mismo sitio que un rechazo.
    config()->set('services.portaldom.api_host', 'apitest.este-host-no-existe.invalid');
    app()->forgetInstance(CybersourceClient::class);

    $resultado = app(CobrarConTarjeta::class)(
        CobroSolicitado::conPanDeSandbox(
            referencia: referenciaDePrueba(),
            importeCents: 12_300,
            tarjeta: tarjetaDePrueba(),
            idempotencyKey: (string) Str::uuid(),
            facturacion: facturacionDePrueba(),
        )
    );

    fwrite(STDERR, "\n[sandbox] corte de transporte → ".json_encode($resultado->paraLog())."\n");

    expect($resultado->desenlace())->toBe(DesenlaceDeCobro::Incierto)
        ->and($resultado->esIncierto())->toBeTrue()
        ->and($resultado->esRechazado())->toBeFalse()
        ->and($resultado->esAprobado())->toBeFalse()
        ->and($resultado->httpStatus)->toBe(0);
})->group('cybersource');

test('repeating a charge with the same idempotency key does not charge twice', function (): void {
    $accion = app(CobrarConTarjeta::class);
    $llave = (string) Str::uuid();
    $referencia = referenciaDePrueba();

    $cobro = fn (): CobroSolicitado => CobroSolicitado::conPanDeSandbox(
        referencia: $referencia,
        importeCents: 25_000,
        tarjeta: tarjetaDePrueba(),
        idempotencyKey: $llave,
        facturacion: facturacionDePrueba(),
    );

    $primero = $accion($cobro());
    $segundo = $accion($cobro());

    fwrite(STDERR, "\n[sandbox] idempotencia → 1ª {$primero->transactionId} · 2ª {$segundo->transactionId}\n");

    expect($primero->esAprobado())->toBeTrue()
        ->and($segundo)->toBeInstanceOf(ResultadoDeCobro::class);

    // Mismo cuerpo + misma llave dentro de la ventana de 15 minutos:
    // Cybersource DEBERÍA devolver la respuesta cacheada en vez de cobrar
    // otra vez. Medido el 2026-08-07 contra este MID de sandbox, NO lo hace:
    // dos transacciones distintas, las dos AUTHORIZED, con la cabecera
    // demostradamente en el request (lo fija el test de arriba). O sea: la
    // idempotencia hay que pedirla habilitada a PortalDOM — no viene puesta.
    //
    // El test no se borra ni se afloja: queda incompleto y con el motivo, así
    // que el día que la habiliten empieza a exigir lo que toca sin que nadie
    // se acuerde de volver aquí.
    if ($segundo->transactionId !== $primero->transactionId) {
        $this->markTestIncomplete(
            'El MID de sandbox NO honra v-c-idempotency-id: dos cobros distintos con la misma llave '
            ."({$primero->transactionId} y {$segundo->transactionId}). Pedir la habilitación a PortalDOM."
        );
    }

    expect($segundo->transactionId)->toBe($primero->transactionId);
})->group('cybersource');
