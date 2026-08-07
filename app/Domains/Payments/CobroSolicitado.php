<?php

declare(strict_types=1);

namespace App\Domains\Payments;

use App\Domains\Payments\Enums\ModoDeCobro;
use App\Domains\Payments\Exceptions\PaymentsException;

/**
 * Lo que hay que saber para cobrar una vez.
 *
 * Es un objeto de valor con constructores por modo, no una bolsa de
 * parámetros opcionales: así no existe la combinación imposible («token
 * guardado» + PAN, o «tarjeta nueva» sin JWT) y el que llama no puede
 * inventarla. La validación de importe, referencia y llave de idempotencia
 * pasa AQUÍ, antes de que nada salga a la red.
 *
 * El importe viaja en **centavos enteros**, como todo el dinero de la casa.
 * El formateo a «1234.50» ocurre solo al armar el cuerpo.
 */
final readonly class CobroSolicitado
{
    /**
     * @param  array<string, string>  $tarjetaDeSandbox  number, exp_month, exp_year, cvv, type
     * @param  array<string, string>  $facturacion  `orderInformation.billTo` tal cual
     */
    private function __construct(
        public ModoDeCobro $modo,
        public string $referencia,
        public int $importeCents,
        public string $idempotencyKey,
        public bool $guardarTarjeta,
        public ?string $transientTokenJwt = null,
        public ?string $customerTokenId = null,
        public ?string $paymentInstrumentId = null,
        public array $tarjetaDeSandbox = [],
        public array $facturacion = [],
        public ?string $identificadorDeCliente = null,
        public ?string $ip = null,
    ) {
        if ($this->importeCents <= 0) {
            throw PaymentsException::importeInvalido($this->importeCents);
        }

        if (trim($this->referencia) === '') {
            throw PaymentsException::referenciaVacia();
        }

        $longitud = mb_strlen($this->idempotencyKey);
        if ($longitud === 0 || $longitud > 64) {
            throw PaymentsException::idempotencyKeyInvalida($longitud);
        }

        // Y la credencial del modo, con contenido. Un `customerTokenId` o un
        // `transientTokenJwt` en blanco no se quedan fuera del cuerpo: salen
        // como cadena vacía, que Cybersource RECHAZA en vez de ignorar
        // (lección 7 de §0.2). El filtro `sinVacios()` de la acción solo cubre
        // el bloque `card`, así que estos dos pasaban enteros y volvían como
        // un INVALID_REQUEST que se podía haber evitado sin salir a la red.
        match ($this->modo) {
            ModoDeCobro::TarjetaNueva => self::exigirContenido('tokenInformation.transientTokenJwt', $this->transientTokenJwt),
            ModoDeCobro::TarjetaGuardada => self::exigirContenido('paymentInformation.customer.id', $this->customerTokenId),
            ModoDeCobro::PanDeSandbox => null,
        };

        // El instrumento es opcional —se cobra solo con el customer cuando hay
        // una sola tarjeta—, pero si viene, viene con algo dentro.
        if ($this->paymentInstrumentId !== null) {
            self::exigirContenido('paymentInformation.paymentInstrument.id', $this->paymentInstrumentId);
        }
    }

    private static function exigirContenido(string $campo, ?string $valor): void
    {
        if ($valor === null || trim($valor) === '') {
            throw PaymentsException::credencialVacia($campo);
        }
    }

    /**
     * Alta de tarjeta CON compra: cobra y tokeniza en UNA sola llamada.
     *
     * El `transientTokenJwt` caduca a los 15 minutos y viene de una captura
     * que no pasó por este servidor. Por eso este camino no se encola: se
     * cobra en la misma petición o se pide la tarjeta otra vez.
     *
     * No lleva `facturacion`: los datos del titular viajan DENTRO del token,
     * capturados por Cybersource en la misma pantalla que el PAN.
     */
    public static function conTarjetaNueva(
        string $referencia,
        int $importeCents,
        string $transientTokenJwt,
        string $idempotencyKey,
        ?string $identificadorDeCliente = null,
        ?string $ip = null,
    ): self {
        return new self(
            modo: ModoDeCobro::TarjetaNueva,
            referencia: $referencia,
            importeCents: $importeCents,
            idempotencyKey: $idempotencyKey,
            guardarTarjeta: true,
            transientTokenJwt: $transientTokenJwt,
            identificadorDeCliente: $identificadorDeCliente,
            ip: $ip,
        );
    }

    /**
     * Compra de dos toques: el token guardado y nada más. Cero datos de
     * tarjeta en tránsito, cero recaptura, cero CVV.
     */
    public static function conTarjetaGuardada(
        string $referencia,
        int $importeCents,
        string $customerTokenId,
        string $idempotencyKey,
        ?string $paymentInstrumentId = null,
        ?string $identificadorDeCliente = null,
        ?string $ip = null,
    ): self {
        return new self(
            modo: ModoDeCobro::TarjetaGuardada,
            referencia: $referencia,
            importeCents: $importeCents,
            idempotencyKey: $idempotencyKey,
            guardarTarjeta: false,
            customerTokenId: $customerTokenId,
            paymentInstrumentId: $paymentInstrumentId,
            identificadorDeCliente: $identificadorDeCliente,
            ip: $ip,
        );
    }

    /**
     * PAN en claro — SOLO sandbox. Ver ModoDeCobro::PanDeSandbox para el
     * porqué de que esto no pueda existir en producción.
     *
     * Aquí sí hacen falta los datos de facturación: sin token que los lleve
     * dentro, Cybersource los exige para autorizar con tarjeta.
     *
     * @param  array<string, string>  $tarjeta
     * @param  array<string, string>  $facturacion
     */
    public static function conPanDeSandbox(
        string $referencia,
        int $importeCents,
        array $tarjeta,
        string $idempotencyKey,
        array $facturacion = [],
        bool $guardarTarjeta = false,
        ?string $identificadorDeCliente = null,
        ?string $ip = null,
    ): self {
        return new self(
            modo: ModoDeCobro::PanDeSandbox,
            referencia: $referencia,
            importeCents: $importeCents,
            idempotencyKey: $idempotencyKey,
            guardarTarjeta: $guardarTarjeta,
            tarjetaDeSandbox: $tarjeta,
            facturacion: $facturacion,
            identificadorDeCliente: $identificadorDeCliente,
            ip: $ip,
        );
    }

    /**
     * El importe tal como lo espera Cybersource: «1234.50».
     *
     * Se compone con enteros a propósito. `number_format($cents / 100, 2)`
     * mete el importe en un float por el camino, y hay céntimos que en
     * binario no existen exactos: el redondeo que eso introduce es invisible
     * en los importes de un test y aparece en el de alguien. Aquí no hay
     * división: parte entera y resto.
     */
    public function importeFormateado(): string
    {
        return sprintf('%d.%02d', intdiv($this->importeCents, 100), $this->importeCents % 100);
    }
}
