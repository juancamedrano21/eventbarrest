<?php

declare(strict_types=1);

namespace App\Domains\Payments;

/**
 * Una transacción tal como la devuelve `/tss/v2/searches`, ya leída.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * OJO: LA BÚSQUEDA NO DEVUELVE `status`. EL ÁRBITRO AQUÍ ES OTRO.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * La regla dura de `EstadoDeCobro` —«`body.status` es el único árbitro»— vale
 * para `/pts/v2/payments`. El resumen de la búsqueda tiene otra forma y NO
 * trae `status`; lo comprobado contra apitest el 2026-08-07 es esto:
 *
 *   aprobado → applicationInformation: {reasonCode: "100", rCode: "1",
 *              rFlag: "SOK", applications: [ics_auth SOK, ics_bill SOK]}
 *   rechazado → applicationInformation: {reasonCode: "231", rCode: "0",
 *              rFlag: "DINVALIDCARD", applications: [ics_auth DINVALIDCARD,
 *              ics_bill (vacío)]}
 *
 * Por eso `aprobado` se deriva de `reasonCode` + `rFlag`, y se exige la
 * combinación EXACTA de éxito: cualquier otra cosa —incluido un rFlag que no
 * conozcamos— sale como no aprobada. Esta clase es para CONCILIAR, no para
 * despachar: quien despacha sigue siendo el `ResultadoDeCobro` del cobro.
 */
final readonly class CobroEncontrado
{
    private function __construct(
        public ?string $transactionId,
        public ?string $referencia,
        public bool $aprobado,
        public ?string $motivo,
        public ?string $mensaje,
        public ?string $submitTimeUtc,
        public ?string $importe,
        public ?string $moneda,
    ) {}

    /**
     * @param  array<string, mixed>  $resumen  un `_embedded.transactionSummaries[]`
     */
    public static function desdeResumen(array $resumen): self
    {
        $reasonCode = self::texto(self::en($resumen, ['applicationInformation', 'reasonCode']));
        $rFlag = self::texto(self::en($resumen, ['applicationInformation', 'rFlag']));

        return new self(
            transactionId: self::texto($resumen['id'] ?? null),
            referencia: self::texto(self::en($resumen, ['clientReferenceInformation', 'code'])),

            // La combinación exacta del éxito, y solo esa.
            aprobado: $reasonCode === '100' && $rFlag === 'SOK',

            // En el rechazo medido `errorInformation` venía vacío y el motivo
            // estaba en el rFlag, así que se coalescen: primero el que dice
            // algo concreto, y si no, la bandera.
            motivo: self::texto(self::en($resumen, ['errorInformation', 'reason'])) ?? $rFlag,
            mensaje: self::primerMensaje($resumen),

            submitTimeUtc: self::texto($resumen['submitTimeUtc'] ?? null),
            importe: self::texto(self::en($resumen, ['orderInformation', 'amountDetails', 'totalAmount'])),
            moneda: self::texto(self::en($resumen, ['orderInformation', 'amountDetails', 'currency'])),
        );
    }

    /**
     * Lo que SÍ se puede escribir en un log. El resumen entero no: trae el
     * billTo del titular y los datos de la tarjeta enmascarados por
     * Cybersource, y nada de eso hace falta para conciliar.
     *
     * @return array<string, mixed>
     */
    public function paraLog(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'referencia' => $this->referencia,
            'aprobado' => $this->aprobado,
            'motivo' => $this->motivo,
            'submit_time_utc' => $this->submitTimeUtc,
            'importe' => $this->importe,
            'moneda' => $this->moneda,
        ];
    }

    /**
     * El primer `rMessage` que traiga alguna de las aplicaciones. Es el texto
     * que explica el rechazo cuando `errorInformation` viene vacío.
     *
     * @param  array<string, mixed>  $resumen
     */
    private static function primerMensaje(array $resumen): ?string
    {
        $mensaje = self::texto(self::en($resumen, ['errorInformation', 'message']));

        if ($mensaje !== null) {
            return $mensaje;
        }

        $aplicaciones = self::en($resumen, ['applicationInformation', 'applications']);

        if (! is_array($aplicaciones)) {
            return null;
        }

        foreach ($aplicaciones as $aplicacion) {
            if (is_array($aplicacion) && ($texto = self::texto($aplicacion['rMessage'] ?? null)) !== null) {
                return $texto;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @param  list<string>  $ruta
     */
    private static function en(array $cuerpo, array $ruta): mixed
    {
        $actual = $cuerpo;

        foreach ($ruta as $clave) {
            if (! is_array($actual) || ! array_key_exists($clave, $actual)) {
                return null;
            }
            $actual = $actual[$clave];
        }

        return $actual;
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }
}
