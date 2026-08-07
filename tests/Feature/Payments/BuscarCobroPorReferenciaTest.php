<?php

declare(strict_types=1);

use App\Domains\Payments\Actions\BuscarCobroPorReferencia;
use App\Domains\Payments\ConciliacionDeCobro;
use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\Services\CybersourceClient;

/**
 * La búsqueda con la ida a la red sustituida por una respuesta enlatada. Los
 * cuerpos son los LITERALES que devolvió apitest.cybersource.com el
 * 2026-08-07, recortados a lo que se lee.
 *
 * @param  array<string, mixed>  $respuesta
 */
function busquedaQueResponde(array $respuesta): BuscarCobroPorReferencia
{
    return new class(new CybersourceClient, $respuesta) extends BuscarCobroPorReferencia
    {
        /** @param array<string, mixed> $respuesta */
        public function __construct(CybersourceClient $cliente, private readonly array $respuesta)
        {
            parent::__construct($cliente);
        }

        protected function llamar(string $referencia): array
        {
            return $this->respuesta;
        }
    };
}

/** @return array<string, mixed> */
function resumenAprobado(string $referencia): array
{
    return [
        'id' => '7861326915776133003812',
        'submitTimeUtc' => '2026-08-07T19:58:11Z',
        'applicationInformation' => [
            'reasonCode' => '100',
            'rCode' => '1',
            'rFlag' => 'SOK',
            'applications' => [
                ['name' => 'ics_auth', 'reasonCode' => '100', 'rFlag' => 'SOK', 'rMessage' => 'Request was processed successfully.'],
                ['name' => 'ics_bill', 'reasonCode' => '100', 'rFlag' => 'SOK', 'rMessage' => 'Request was processed successfully.'],
            ],
        ],
        'clientReferenceInformation' => ['code' => $referencia],
        'orderInformation' => ['amountDetails' => ['totalAmount' => '123.00', 'currency' => 'DOP']],
    ];
}

/** @return array<string, mixed> */
function resumenRechazado(string $referencia): array
{
    return [
        'id' => '7861327413326147703812',
        'submitTimeUtc' => '2026-08-07T19:59:01Z',
        'applicationInformation' => [
            'reasonCode' => '231',
            'rCode' => '0',
            'rFlag' => 'DINVALIDCARD',
            'applications' => [
                [
                    'name' => 'ics_auth',
                    'reasonCode' => '231',
                    'rFlag' => 'DINVALIDCARD',
                    'rMessage' => 'The following request field(s) is either invalid or missing: customer_cc_number',
                ],
                ['name' => 'ics_bill'],
            ],
        ],
        'clientReferenceInformation' => ['code' => $referencia],
        // En el rechazo medido `errorInformation` viene VACÍO y el motivo está
        // en el rFlag. Por eso no se lee solo de ahí.
        'errorInformation' => [],
        'orderInformation' => ['amountDetails' => []],
    ];
}

// ── La consulta que se manda ────────────────────────────────────────────

it('asks by our own reference and nothing else', function (): void {
    $consulta = app(BuscarCobroPorReferencia::class)->consulta('EBR-0001');

    expect($consulta['query'])->toBe('clientReferenceInformation.code:"EBR-0001"')
        ->and($consulta['save'])->toBeFalse()
        ->and($consulta['timezone'])->toBe('America/Santo_Domingo')
        // Más de uno significa que ya hubo duplicado, y eso hay que verlo
        // entero, no recortado al primero.
        ->and($consulta['limit'])->toBeGreaterThan(1);
});

it('refuses a reference that would change the query instead of failing', function (string $referencia): void {
    // El campo es un índice de texto: una comilla no rompe la consulta, la
    // reescribe — y entonces la conciliación contesta por otra transacción,
    // que es peor que no contestar.
    app(BuscarCobroPorReferencia::class)->consulta($referencia);
})->with([
    'a quote' => 'EBR-0001" OR code:*',
    'a backslash' => 'EBR-0001\\',
])->throws(PaymentsException::class, 'no se puede consultar');

it('refuses an empty reference', function (): void {
    app(BuscarCobroPorReferencia::class)('  ');
})->throws(PaymentsException::class, 'referencia');

// ── Lo que se lee de la respuesta ───────────────────────────────────────

it('reads an approved transaction out of the search summary', function (): void {
    // La búsqueda NO devuelve `status`: el árbitro aquí es
    // `applicationInformation` (reasonCode 100 + rFlag SOK).
    $conciliacion = busquedaQueResponde([
        'totalCount' => 1,
        '_embedded' => ['transactionSummaries' => [resumenAprobado('EBR-0002')]],
    ])('EBR-0002');

    expect($conciliacion->hayRastro())->toBeTrue()
        ->and($conciliacion->total)->toBe(1)
        ->and($conciliacion->cobroAprobado()?->transactionId)->toBe('7861326915776133003812')
        ->and($conciliacion->cobroAprobado()?->importe)->toBe('123.00')
        ->and($conciliacion->cobroAprobado()?->moneda)->toBe('DOP');
});

it('does not call a declined transaction approved', function (): void {
    $conciliacion = busquedaQueResponde([
        'totalCount' => 1,
        '_embedded' => ['transactionSummaries' => [resumenRechazado('EBR-0003')]],
    ])('EBR-0003');

    expect($conciliacion->hayRastro())->toBeTrue()
        ->and($conciliacion->cobroAprobado())->toBeNull()
        ->and($conciliacion->cobros[0]->aprobado)->toBeFalse()
        // El motivo sale del rFlag porque `errorInformation` viene vacío.
        ->and($conciliacion->cobros[0]->motivo)->toBe('DINVALIDCARD')
        ->and($conciliacion->cobros[0]->mensaje)->toContain('customer_cc_number');
});

it('approves only the exact success combination', function (string $reasonCode, string $rFlag): void {
    $resumen = resumenAprobado('EBR-0004');
    $resumen['applicationInformation']['reasonCode'] = $reasonCode;
    $resumen['applicationInformation']['rFlag'] = $rFlag;

    $conciliacion = busquedaQueResponde([
        'totalCount' => 1,
        '_embedded' => ['transactionSummaries' => [$resumen]],
    ])('EBR-0004');

    expect($conciliacion->cobroAprobado())->toBeNull();
})->with([
    'a flag invented tomorrow' => ['100', 'SOK_PARTIAL'],
    'a reason code that is not 100' => ['480', 'SOK'],
    'both unknown' => ['999', 'DUNKNOWN'],
]);

it('reports every transaction when the reference was already charged twice', function (): void {
    // Si aparecen dos, soporte necesita los DOS ids: es la prueba del doble
    // cobro, y quedarse con el primero la esconde.
    $conciliacion = busquedaQueResponde([
        'totalCount' => 2,
        '_embedded' => ['transactionSummaries' => [resumenAprobado('EBR-0005'), resumenRechazado('EBR-0005')]],
    ])('EBR-0005');

    expect($conciliacion->cobros)->toHaveCount(2)
        ->and($conciliacion->total)->toBe(2);
});

// ── La decisión: ¿se puede reintentar? ──────────────────────────────────

it('never authorises a retry while the transaction could still be unindexed', function (): void {
    // Medido contra apitest: a 0,3 s del cobro la búsqueda devolvía
    // `totalCount: 0` y a 4,6 s ya devolvía la transacción. Un cero inmediato
    // después del corte —que es justo cuando uno pregunta— NO prueba que no se
    // cobró, y darlo por bueno es el doble cobro.
    $sinRastro = busquedaQueResponde(['totalCount' => 0])('EBR-0006');

    expect($sinRastro->hayRastro())->toBeFalse()
        ->and($sinRastro->sePuedeReintentar(0))->toBeFalse()
        ->and($sinRastro->sePuedeReintentar(2))->toBeFalse()
        ->and($sinRastro->sePuedeReintentar(ConciliacionDeCobro::SEGUNDOS_DE_INDEXADO - 1))->toBeFalse()
        ->and($sinRastro->sePuedeReintentar(ConciliacionDeCobro::SEGUNDOS_DE_INDEXADO))->toBeTrue();
});

it('never authorises a retry when there is any trace at all', function (): void {
    // Ni siquiera con el cobro rechazado: reenviar la misma referencia deja a
    // PortalDOM viendo dos intentos donde hubo uno.
    $conRechazo = busquedaQueResponde([
        'totalCount' => 1,
        '_embedded' => ['transactionSummaries' => [resumenRechazado('EBR-0007')]],
    ])('EBR-0007');

    expect($conRechazo->sePuedeReintentar(3_600))->toBeFalse();
});

it('handles a reference that does not exist without inventing an error', function (): void {
    // Comprobado contra apitest: una referencia inexistente devuelve
    // `totalCount: 0` y `_embedded` ausente, no un fallo.
    $conciliacion = busquedaQueResponde(['totalCount' => 0, 'count' => 0])('EBR-0008');

    expect($conciliacion->hayRastro())->toBeFalse()
        ->and($conciliacion->cobros)->toBe([])
        ->and($conciliacion->cobroAprobado())->toBeNull();
});
