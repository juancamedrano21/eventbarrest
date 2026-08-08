<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use Throwable;

/**
 * La aduana por la que pasa TODO texto que viene de Cybersource antes de
 * poder acabar en un log o en una respuesta.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EL MENSAJE DEL SDK LLEVA LA CREDENCIAL DE COBRO DENTRO. MEDIDO.
 * ─────────────────────────────────────────────────────────────────────────
 * `ApiClient::callApi()` compone el mensaje de `ApiException` como
 * «[401] Error connecting to the API ($url)», y cuando la llamada es al TMS
 * esa URL es
 * `…/tms/v2/customers/{customerTokenId}/payment-instruments/{paymentInstrumentId}`:
 * las DOS piezas de la credencial, enteras, dentro de una cadena que hasta
 * ahora se interpolaba tal cual en el mensaje de la excepción — y de ahí a
 * `Log::error`, y con `APP_DEBUG=true` al cuerpo del 500 que recibe el
 * teléfono. Con `paymentInformation.customer.id` se cobra en
 * `/pts/v2/payments`, así que eso escrito en `laravel.log` es una credencial
 * de cobro: el mismo fallo que la doc 12 §0.3 marca como «no copiar de
 * Boletu», mudado de la tabla al fichero de log.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ES UNA ADUANA Y NO UN PARCHE POR SITIO, A PROPÓSITO.
 * ─────────────────────────────────────────────────────────────────────────
 * La versión «taparlo donde se vio» ya se intentó una vez en
 * `CobrarConTarjeta::redactado()` con un `if` por campo, y se olvidó uno: el
 * mismo array escribía el `payment_instrument` truncado en una clave y
 * entero en la de al lado. Un olvido no se arregla acordándose mejor. Por eso
 * esta función es obligatoria por construcción en los dos únicos caminos que
 * existen: `PaymentsException` la aplica a su mensaje en el CONSTRUCTOR —así
 * que ninguna fábrica futura puede saltársela— y el resto de sitios que
 * registran un error del SDK sin excepción propia usan `de()`.
 *
 * DOS REGLAS, y son complementarias a propósito:
 *
 * 1. **Por posición**: el segmento que sigue a una colección del TMS en una
 *    URL es siempre un id de token, tenga la forma que tenga. Cubre los
 *    formatos que Cybersource estrene mañana.
 * 2. **Por forma**: los ids del TMS son hexadecimal en mayúsculas de 32
 *    caracteres (`588DE8933E18D582E063AF598E0A5129`, medido). Cubre el token
 *    que aparezca fuera de una URL — en el cuerpo de un error, por ejemplo.
 *
 * Lo que NO se toca es el id de transacción de `/pts/v2/payments/{id}/voids`:
 * no es una credencial —con él no se cobra a nadie— y es lo único con lo que
 * se reconcilia un cobro atascado. Por eso `payments` no está en la lista de
 * colecciones y por eso la regla de forma pide 32 caracteres y no menos: un
 * id de transacción tiene 22 dígitos y sobrevive entero.
 */
final class MensajeDeCybersource
{
    /**
     * Las colecciones del TMS cuyo siguiente segmento de URL es un token.
     *
     * `payments` no está y no es un olvido: ver el porqué arriba.
     */
    private const COLECCIONES_DEL_TMS = 'customers|payment-instruments|paymentinstruments|instrumentidentifiers';

    /**
     * Por debajo de esto no hay token que valga: sirve para no destrozar los
     * marcadores de las rutas que la casa escribe a mano
     * (`DELETE /tms/v2/customers/{c}/payment-instruments/{pi}`), que son parte
     * del mensaje útil y no una credencial.
     */
    private const LONGITUD_MINIMA_DE_UN_TOKEN = 12;

    /** El mensaje de un fallo del SDK, ya redactado. */
    public static function de(Throwable $e): string
    {
        return self::redactado($e->getMessage());
    }

    public static function redactado(string $mensaje): string
    {
        $porPosicion = preg_replace_callback(
            '#/('.self::COLECCIONES_DEL_TMS.')/([^/\s)?\#]+)#i',
            static fn (array $coincidencia): string => '/'.$coincidencia[1].'/'.self::huella($coincidencia[2]),
            $mensaje,
        );

        $porForma = preg_replace_callback(
            '/\b[0-9A-F]{32}\b/',
            static fn (array $coincidencia): string => self::huella($coincidencia[0]),
            $porPosicion ?? $mensaje,
        );

        return $porForma ?? $mensaje;
    }

    /**
     * Los últimos cuatro caracteres, que es todo lo que puede salir a un log
     * —la misma huella que usa `AccionSobreLaBoveda`, para que soporte pueda
     * cruzar una línea de log con una fila sin que quede la llave escrita.
     */
    private static function huella(string $token): string
    {
        if (mb_strlen($token) < self::LONGITUD_MINIMA_DE_UN_TOKEN) {
            return $token;
        }

        return '…'.mb_substr($token, -4);
    }
}
