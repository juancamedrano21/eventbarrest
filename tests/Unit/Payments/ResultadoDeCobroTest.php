<?php

declare(strict_types=1);

use App\Domains\Payments\Enums\DesenlaceDeCobro;
use App\Domains\Payments\Enums\EstadoDeCobro;
use App\Domains\Payments\ResultadoDeCobro;

it('reads status, ids and tokens from an approved body', function (): void {
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'id' => '7712345678901234567890',
        'status' => 'AUTHORIZED',
        'processorInformation' => [
            'approvalCode' => '831000',
            'responseCode' => '00',
            'networkTransactionId' => '016153570198200',
        ],
        'tokenInformation' => [
            'customer' => ['id' => 'CUSTOMER-TOKEN-9999'],
            'paymentInstrument' => ['id' => 'INSTRUMENT-TOKEN-8888'],
            'instrumentIdentifier' => ['id' => 'IDENTIFIER-7777'],
        ],
    ], 201);

    expect($resultado->estado)->toBe(EstadoDeCobro::Autorizado)
        ->and($resultado->esAprobado())->toBeTrue()
        ->and($resultado->transactionId)->toBe('7712345678901234567890')
        ->and($resultado->customerTokenId)->toBe('CUSTOMER-TOKEN-9999')
        ->and($resultado->paymentInstrumentId)->toBe('INSTRUMENT-TOKEN-8888')
        ->and($resultado->instrumentIdentifierId)->toBe('IDENTIFIER-7777')
        ->and($resultado->tieneToken())->toBeTrue();
});

it('anchors chaining on networkTransactionId and not on the transaction id', function (): void {
    // Son dos identificadores distintos y confundirlos deja los cobros
    // siguientes sin poder encadenar. Este test existe para que el día que
    // alguien «simplifique» leyendo `id`, se entere aquí.
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'id' => '7712345678901234567890',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => '016153570198200'],
    ], 201);

    expect($resultado->networkTransactionId)->toBe('016153570198200')
        ->and($resultado->networkTransactionId)->not->toBe($resultado->transactionId);
});

it('rejects a charge the processor approved but decision manager declined', function (): void {
    // responseCode "00" + approvalCode válido + AUTHORIZED_RISK_DECLINED.
    // Es el cuerpo que hace perder dinero a quien lee el código.
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'id' => '7799999999999999999999',
        'status' => 'AUTHORIZED_RISK_DECLINED',
        'processorInformation' => [
            'approvalCode' => '831000',
            'responseCode' => '00',
            'networkTransactionId' => '016153570198200',
        ],
    ], 201);

    expect($resultado->esAprobado())->toBeFalse()
        ->and($resultado->esRechazado())->toBeTrue()
        ->and($resultado->crudo['processorInformation']['responseCode'])->toBe('00');
});

it('does not approve an empty body', function (): void {
    $resultado = ResultadoDeCobro::desdeRespuesta([], 500);

    expect($resultado->estado)->toBe(EstadoDeCobro::Desconocido)
        ->and($resultado->esAprobado())->toBeFalse()
        ->and($resultado->transactionId)->toBeNull()
        ->and($resultado->networkTransactionId)->toBeNull()
        ->and($resultado->tieneToken())->toBeFalse();
});

it('treats blank strings as missing rather than as values', function (): void {
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'id' => '   ',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => ''],
        'tokenInformation' => ['customer' => ['id' => '']],
    ], 201);

    expect($resultado->transactionId)->toBeNull()
        ->and($resultado->networkTransactionId)->toBeNull()
        ->and($resultado->tieneToken())->toBeFalse();
});

it('reads the decline reason from errorInformation, where cybersource puts it', function (): void {
    // Cuerpo LITERAL de apitest (2026-08-07, PAN 4111111111111112). Las
    // claves de raíz solo aparecen en los 4xx de validación, así que leer
    // solo la raíz dejaba el motivo en NULL justo en los rechazos de verdad.
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'id' => '7861327413326147703812',
        'status' => 'DECLINED',
        'errorInformation' => [
            'reason' => 'INVALID_ACCOUNT',
            'message' => 'Decline - Invalid account number',
        ],
    ], 201);

    expect($resultado->motivo)->toBe('INVALID_ACCOUNT')
        ->and($resultado->mensaje)->toBe('Decline - Invalid account number');
});

it('still reads the root reason of a validation 4xx', function (): void {
    // El 400 MISSING_FIELD sí las trae en la raíz, y eso no se rompe.
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'status' => 'INVALID_REQUEST',
        'reason' => 'MISSING_FIELD',
        'message' => 'Declined - The request is missing one or more fields',
    ], 400);

    expect($resultado->motivo)->toBe('MISSING_FIELD')
        ->and($resultado->mensaje)->toBe('Declined - The request is missing one or more fields');
});

it('demands both halves of the credential before calling the card saved', function (): void {
    $soloCliente = ResultadoDeCobro::desdeRespuesta([
        'status' => 'AUTHORIZED',
        'tokenInformation' => ['customer' => ['id' => 'CUSTOMER-TOKEN']],
    ], 201);

    $soloInstrumento = ResultadoDeCobro::desdeRespuesta([
        'status' => 'AUTHORIZED',
        'tokenInformation' => ['paymentInstrument' => ['id' => 'INSTRUMENT-TOKEN']],
    ], 201);

    $completo = ResultadoDeCobro::desdeRespuesta([
        'status' => 'AUTHORIZED',
        'tokenInformation' => [
            'customer' => ['id' => 'CUSTOMER-TOKEN'],
            'paymentInstrument' => ['id' => 'INSTRUMENT-TOKEN'],
        ],
    ], 201);

    // Con media credencial la tarjeta guardada no sirve: el `customer` agrupa
    // las tarjetas y el `paymentInstrument` es la tarjeta concreta.
    expect($soloCliente->tieneToken())->toBeFalse()
        ->and($soloInstrumento->tieneToken())->toBeFalse()
        ->and($completo->tieneToken())->toBeTrue();
});

it('reports no answer as its own outcome, not as a decline', function (): void {
    $resultado = ResultadoDeCobro::sinRespuesta('API call to … failed: Operation timed out');

    expect($resultado->estado)->toBe(EstadoDeCobro::SinRespuesta)
        ->and($resultado->desenlace())->toBe(DesenlaceDeCobro::Incierto)
        ->and($resultado->esIncierto())->toBeTrue()
        ->and($resultado->esRechazado())->toBeFalse()
        ->and($resultado->esAprobado())->toBeFalse()
        ->and($resultado->httpStatus)->toBe(0)
        ->and($resultado->transactionId)->toBeNull()
        // No hay cuerpo que guardar: lo único que se sabe es el corte.
        ->and($resultado->crudo)->toBe([])
        ->and($resultado->mensaje)->toContain('Operation timed out');
});

it('keeps the token out of the log payload', function (): void {
    // El cuerpo entero jamás va al log: lleva dentro la credencial con la que
    // se cobra. Del token queda una huella suficiente para reconciliar.
    $resultado = ResultadoDeCobro::desdeRespuesta([
        'id' => '77123',
        'status' => 'AUTHORIZED',
        'processorInformation' => ['networkTransactionId' => '0161535'],
        'tokenInformation' => ['customer' => ['id' => 'CUSTOMER-TOKEN-9999']],
    ], 201);

    $log = $resultado->paraLog();

    expect(json_encode($log))->not->toContain('CUSTOMER-TOKEN-9999')
        ->and($log['customer_token'])->toBe('…9999')
        ->and($log['estado'])->toBe('AUTHORIZED')
        ->and($log['desenlace'])->toBe('aprobado');
});
