<?php

declare(strict_types=1);

namespace App\Domains\Payments;

use App\Domains\Payments\Exceptions\PaymentsException;

/**
 * El seguro que impide que una máquina de desarrollo cobre de verdad.
 *
 * El fallo que evita es aburrido y caro: alguien copia el `.env` de
 * producción para reproducir un bug, arranca los tests o abre la app en
 * local, y cada cobro de prueba sale contra el emisor real, con dinero real
 * de una tarjeta real. No lo dice ningún error: el pago funciona. Se
 * descubre en el estado de cuenta.
 *
 * Por eso la comprobación no vive en un comentario ni en la revisión de
 * código: `PORTALDOM_ENV=live` fuera de `APP_ENV=production` deja la
 * aplicación sin arrancar. Se llama desde DOS sitios a propósito:
 *
 * - `config/services.php`, para que reviente al cargar la configuración,
 *   antes de que exista nada más.
 * - el constructor de CybersourceClient, porque con `config:cache` el
 *   fichero de configuración no se ejecuta y la primera puerta no se abre.
 *
 * El defecto de `APP_ENV` al comprobar es `local`, no `production`: una
 * máquina sin `APP_ENV` puesta se trata como la más peligrosa, no como la
 * más confiable.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Y SE COMPRUEBA EL HOST, NO SOLO LA ETIQUETA DEL ENTORNO.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `PORTALDOM_ENV` es una etiqueta; la variable que decide a dónde va el dinero
 * es `PORTALDOM_API_HOST`, porque `ApiClient` arma la URL con
 * `Configuration::getHost()`, que sale de ahí. Un `.env` con
 * `PORTALDOM_ENV=test` y `PORTALDOM_API_HOST=api.cybersource.com` es
 * perfectamente escribible, y con él todos los seguros que miran la etiqueta
 * dan luz verde mientras los cobros salen contra producción — incluido el modo
 * PAN en claro, que es alcance SAQ D. Por eso las dos variables no pueden
 * contradecirse: o las dos dicen pruebas, o las dos dicen producción.
 */
final class EntornoDePortalDom
{
    public const LIVE = 'live';

    public const TEST = 'test';

    public const HOST_SANDBOX = 'apitest.cybersource.com';

    public const HOST_PRODUCCION = 'api.cybersource.com';

    public static function comprobar(string $appEnv, string $portaldomEnv, string $apiHost): void
    {
        $esProduccion = mb_strtolower(trim($appEnv)) === 'production';

        if (self::esLive($portaldomEnv) && ! $esProduccion) {
            throw PaymentsException::entornoLiveFueraDeProduccion($appEnv);
        }

        // El host manda: aunque la etiqueta diga `test`, un host que no es el
        // del sandbox cobra de verdad, y fuera de producción eso es el mismo
        // fallo de arriba entrando por la otra puerta.
        if (! self::esHostDeSandbox($apiHost) && ! $esProduccion) {
            throw PaymentsException::hostDeProduccionFueraDeProduccion($appEnv, $apiHost);
        }

        // Y aunque sea producción, las dos variables tienen que decir lo
        // mismo: un `live` que apunta a apitest no cobra nada y nadie se
        // entera hasta que falta el dinero.
        if (self::esLive($portaldomEnv) === self::esHostDeSandbox($apiHost)) {
            throw PaymentsException::entornoYHostSeContradicen($portaldomEnv, $apiHost);
        }
    }

    public static function esLive(?string $portaldomEnv): bool
    {
        return mb_strtolower(trim((string) $portaldomEnv)) === self::LIVE;
    }

    /**
     * Un host es de pruebas SOLO si lo dice su nombre (`apitest.…`).
     *
     * El defecto es la respuesta segura: cualquier host que no reconozcamos se
     * trata como producción, no como sandbox. Al revés —dar por sandbox lo
     * desconocido— es lo que dejaría salir un PAN en claro contra un host de
     * verdad.
     */
    public static function esHostDeSandbox(?string $apiHost): bool
    {
        return str_starts_with(mb_strtolower(trim((string) $apiHost)), 'apitest.');
    }

    /** El host que le toca a un entorno cuando nadie fija `PORTALDOM_API_HOST`. */
    public static function hostPorDefecto(?string $portaldomEnv): string
    {
        return self::esLive($portaldomEnv) ? self::HOST_PRODUCCION : self::HOST_SANDBOX;
    }
}
