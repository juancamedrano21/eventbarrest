<?php

declare(strict_types=1);

namespace App\Domains\Payments;

use App\Domains\Payments\Enums\MarcaDeTarjeta;

/**
 * Lo que la bóveda de Cybersource sabe de una tarjeta guardada, ya tipado.
 *
 * Es el ÚNICO sitio donde se lee el cuerpo de
 * `GET /tms/v2/customers/{c}/payment-instruments/{pi}`, por el mismo motivo
 * que `ResultadoDeCobro` es el único que lee el del cobro: que cada llamador
 * vuelva a hurgar en el array es cómo se acaba leyendo el campo equivocado.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ HAY QUE PREGUNTARLE A LA BÓVEDA EN VEZ DE LEER LA RESPUESTA DEL COBRO
 * ─────────────────────────────────────────────────────────────────────────
 * Medido contra apitest el 2026-08-07: la respuesta de `/pts/v2/payments` con
 * TOKEN_CREATE trae `paymentInformation.card.type` (la marca) y NADA MÁS de
 * la tarjeta — ni últimos 4 ni vencimiento. Con el PAN de sandbox esos dos
 * datos se saben porque los mandamos nosotros, pero en producción la captura
 * ocurre fuera de este servidor (SAQ A) y aquí solo entra un JWT opaco. La
 * bóveda es la única fuente que los tiene, y por eso hay una segunda ida a la
 * red después de tokenizar.
 *
 * La forma que devuelve, medida:
 *   card.expirationMonth "12" · card.expirationYear "2031" · card.type "001"
 *   instrumentIdentifier.id "70388199999891 41111"
 *   _embedded.instrumentIdentifier.card.number "411111XXXXXX1111"
 *
 * De ese número enmascarado se guardan SOLO los últimos cuatro. No es PAN
 * —viene ya tapado por Cybersource— pero tampoco se persiste entero: lo que
 * la app necesita para que el asistente reconozca su tarjeta son cuatro
 * dígitos.
 */
final readonly class TarjetaEnLaBoveda
{
    public function __construct(
        public string $paymentInstrumentId,
        public MarcaDeTarjeta $marca,
        public ?string $ultimos4,
        public ?int $venceMes,
        public ?int $venceAno,
        public ?string $instrumentIdentifierId,
    ) {}

    /**
     * @param  array<string, mixed>  $cuerpo
     */
    public static function desdeRespuesta(array $cuerpo, string $paymentInstrumentId): self
    {
        return new self(
            // El id de la URL manda sobre el del cuerpo: es el que tenemos
            // guardado y con el que se cobra. Si algún día no coincidieran,
            // el que hay que conservar es el nuestro.
            paymentInstrumentId: $paymentInstrumentId,
            marca: MarcaDeTarjeta::desdeCybersource(self::en($cuerpo, ['card', 'type'])),
            ultimos4: self::ultimosCuatro(self::en($cuerpo, ['_embedded', 'instrumentIdentifier', 'card', 'number'])),
            venceMes: self::entero(self::en($cuerpo, ['card', 'expirationMonth'])),
            venceAno: self::entero(self::en($cuerpo, ['card', 'expirationYear'])),
            instrumentIdentifierId: self::texto(self::en($cuerpo, ['instrumentIdentifier', 'id'])),
        );
    }

    /**
     * Los cuatro últimos dígitos del número enmascarado.
     *
     * Solo si son cuatro dígitos de verdad: `411111XXXXXX1111` da «1111», y
     * un enmascarado que tape también el final —hay emisores que lo hacen—
     * da null en vez de «XXXX», que sería basura pintada en la app como si
     * fueran los dígitos de la tarjeta.
     */
    private static function ultimosCuatro(mixed $numero): ?string
    {
        if (! is_string($numero)) {
            return null;
        }

        $cola = mb_substr(trim($numero), -4);

        return preg_match('/^\d{4}$/', $cola) === 1 ? $cola : null;
    }

    /**
     * Cybersource manda el mes y el año como CADENAS («12», «2031»), así que
     * el casteo es aquí y no en quien lea. Un valor que no sea numérico se
     * queda en null: mejor sin vencimiento que con un cero que la app leería
     * como «mes 0».
     */
    private static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }

        if (! is_string($valor) || preg_match('/^\d+$/', trim($valor)) !== 1) {
            return null;
        }

        $entero = (int) trim($valor);

        return $entero > 0 ? $entero : null;
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
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
}
