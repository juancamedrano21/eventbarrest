<?php

declare(strict_types=1);

namespace App\Domains\Payments;

use App\Domains\Payments\Enums\DesenlaceDeCobro;
use App\Domains\Payments\Enums\EstadoDeCobro;

/**
 * La respuesta de Cybersource leída una sola vez y ya tipada.
 *
 * Todo lo que el resto del sistema necesita saber de un cobro sale de aquí, y
 * la lectura del cuerpo crudo ocurre en un único sitio: `desdeRespuesta()`.
 * Que cada llamador vuelva a hurgar en el array es cómo se acaban leyendo
 * campos equivocados —el `id` de la transacción en vez del
 * `networkTransactionId`, el `responseCode` en vez del `status`—.
 */
final readonly class ResultadoDeCobro
{
    /**
     * @param  array<string, mixed>  $crudo
     */
    private function __construct(
        public EstadoDeCobro $estado,
        public int $httpStatus,
        public ?string $transactionId,
        public ?string $networkTransactionId,
        public ?string $customerTokenId,
        public ?string $paymentInstrumentId,
        public ?string $instrumentIdentifierId,
        public ?string $motivo,
        public ?string $mensaje,
        public array $crudo,
    ) {}

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    public static function desdeRespuesta(array $cuerpo, int $httpStatus): self
    {
        return new self(
            estado: EstadoDeCobro::desde($cuerpo['status'] ?? null),
            httpStatus: $httpStatus,
            transactionId: self::texto($cuerpo['id'] ?? null),

            // El ancla del encadenado de credencial en archivo. NO es el `id`
            // de arriba: son dos identificadores distintos y confundirlos deja
            // los cobros siguientes sin poder encadenar. Visa encadena con el
            // de la última transacción exitosa; Mastercard, Amex y Discover,
            // siempre con el del cobro original.
            networkTransactionId: self::texto(
                self::en($cuerpo, ['processorInformation', 'networkTransactionId'])
            ),

            customerTokenId: self::texto(self::en($cuerpo, ['tokenInformation', 'customer', 'id'])),
            paymentInstrumentId: self::texto(self::en($cuerpo, ['tokenInformation', 'paymentInstrument', 'id'])),
            instrumentIdentifierId: self::texto(self::en($cuerpo, ['tokenInformation', 'instrumentIdentifier', 'id'])),

            // ────────────────────────────────────────────────────────────
            // EL MOTIVO DE UN RECHAZO VIVE EN `errorInformation`, NO EN LA
            // RAÍZ. Las claves de raíz solo aparecen en los 4xx de
            // validación, o sea justo donde no hace falta.
            // ────────────────────────────────────────────────────────────
            // Medido contra apitest el 2026-08-07 con el PAN 4111111111111112:
            // {"status":"DECLINED","errorInformation":{"reason":
            // "INVALID_ACCOUNT","message":"Decline - Invalid account number"}}
            // — sin `reason` ni `message` en la raíz. Leer solo la raíz deja
            // el motivo en NULL en todos los rechazos reales, y entonces la
            // app no puede decirle al asistente por qué le rechazaron la
            // tarjeta ni soporte tiene con qué reconciliar con PortalDOM.
            motivo: self::texto(
                self::en($cuerpo, ['errorInformation', 'reason']) ?? $cuerpo['reason'] ?? null
            ),

            // El texto para humanos, aparte del código: el código es lo que
            // clasifica un reintento automático, el mensaje es lo que se le
            // enseña a quien está delante del teléfono.
            mensaje: self::texto(
                self::en($cuerpo, ['errorInformation', 'message']) ?? $cuerpo['message'] ?? null
            ),

            crudo: $cuerpo,
        );
    }

    /**
     * La llamada no llegó a ser una respuesta.
     *
     * Único sitio que fabrica `EstadoDeCobro::SinRespuesta`, y a propósito: es
     * el que sabe que hubo silencio. `desdeRespuesta()` no puede llegar aquí
     * ni con un cuerpo que trajera esa cadena literal.
     *
     * `$crudo` queda vacío porque no hay cuerpo que guardar: lo único que se
     * sabe es el error de transporte, y ese va en `mensaje`.
     */
    public static function sinRespuesta(string $mensaje, int $httpStatus = 0): self
    {
        return new self(
            estado: EstadoDeCobro::SinRespuesta,
            httpStatus: $httpStatus,
            transactionId: null,
            networkTransactionId: null,
            customerTokenId: null,
            paymentInstrumentId: null,
            instrumentIdentifierId: null,
            motivo: 'SIN_RESPUESTA',
            mensaje: self::texto($mensaje),
            crudo: [],
        );
    }

    public function desenlace(): DesenlaceDeCobro
    {
        return $this->estado->desenlace();
    }

    public function esAprobado(): bool
    {
        return $this->estado->esAprobado();
    }

    public function esPendiente(): bool
    {
        return $this->estado->esPendiente();
    }

    public function esRechazado(): bool
    {
        return $this->estado->esRechazado();
    }

    /**
     * ¿Puede haberse cobrado sin que lo sepamos?
     *
     * Es el desenlace que NO se puede confundir con un rechazo: antes de
     * reintentar hay que preguntarle a Cybersource si la referencia ya existe.
     */
    public function esIncierto(): bool
    {
        return $this->estado->esIncierto();
    }

    /**
     * ¿Volvieron LAS DOS piezas de la credencial que se pidieron?
     *
     * El cuerpo pide `actionTokenTypes: ['customer', 'paymentInstrument']`, y
     * con una sola la tarjeta guardada no sirve: el `customer` agrupa las
     * tarjetas del asistente y el `paymentInstrument` es la tarjeta concreta.
     * Conformarse con una dejaba pasar la guarda de invariante un cobro
     * aprobado al que le falta la mitad, y el fallo reaparecía semanas después
     * al intentar cobrar la tarjeta que no se guardó — que es exactamente el
     * «semanas después» que la lección 8 de §0.2 quiere evitar.
     */
    public function tieneToken(): bool
    {
        return $this->customerTokenId !== null && $this->paymentInstrumentId !== null;
    }

    /**
     * Lo que SÍ se puede escribir en un log.
     *
     * El cuerpo entero nunca: lleva dentro el token de la tarjeta, que es la
     * credencial con la que se cobra. Boletu persiste la respuesta completa en
     * una columna y ese es justo el error que aquí no se repite (doc 12 §0.3):
     * el token va a su sitio, y del resto queda un resumen. De los tokens solo
     * se anota si vinieron y sus últimos caracteres, suficiente para
     * reconciliar con soporte sin dejar la llave escrita.
     *
     * @return array<string, mixed>
     */
    public function paraLog(): array
    {
        return [
            'http_status' => $this->httpStatus,
            'estado' => $this->estado->value,
            'desenlace' => $this->desenlace()->value,
            'transaction_id' => $this->transactionId,
            'network_transaction_id' => $this->networkTransactionId,
            'customer_token' => self::huella($this->customerTokenId),
            'payment_instrument' => self::huella($this->paymentInstrumentId),
            'motivo' => $this->motivo,
            'mensaje' => $this->mensaje,
        ];
    }

    /** Los últimos cuatro caracteres de un token, o su ausencia. */
    private static function huella(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        return '…'.mb_substr($token, -4);
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
