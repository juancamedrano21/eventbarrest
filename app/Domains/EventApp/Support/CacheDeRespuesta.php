<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que hace barata la puerta pública de la app: que la respuesta se calcule
 * UNA vez por evento y no una vez por teléfono.
 *
 * POR QUÉ EXISTE ESTO Y NO UN LIMITADOR. Esta puerta se quedó sin freno por IP
 * a conciencia —con `trustProxies(at: '*')` la IP la escribe quien llama, así
 * que el cubo no contaba contra quien ataca y sí contra el festival entero
 * detrás del NAT de su operador; el razonamiento largo está en
 * AppServiceProvider—. Lo que quedó sosteniendo la puerta era el ETag, y el
 * ETag NO AHORRA TRABAJO DE SERVIDOR: un 304 ejecuta exactamente las mismas
 * consultas que un 200 y solo se ahorra los bytes del cuerpo. Ahorra red, que
 * en un recinto saturado vale mucho, pero no ahorra ni una consulta.
 *
 * Aquí se cierra el hueco por donde se cierra de verdad: haciendo la respuesta
 * barata. Los tres endpoints son de solo lectura y su cuerpo es IDÉNTICO para
 * todo el que pregunte por el mismo evento —no hay usuario, no hay token, no
 * hay nada personalizado—, así que calcularlo seis mil veces es calcular seis
 * mil veces lo mismo. El primer teléfono lo paga y los demás lo leen.
 *
 * Y como el ETag se calcula sobre el cuerpo ya cacheado, un 304 tampoco toca
 * la base: era el caso que más duele, porque es el que más se repite.
 *
 * LO QUE NO SE CACHEA, Y ES LA MITAD IMPORTANTE. Solo entra aquí el CUERPO. La
 * puerta —resolver el evento del código, la cuenta del evento, el comercio y
 * su participación— se vuelve a ejecutar en cada petición, porque en esta
 * puerta no hay token que revocar y esa revalidación es la ÚNICA revocación
 * que existe: un comercio suspendido a media tarde recibe 404 en su carta en
 * la petición siguiente, no cuando caduque un TTL. Una revocación cacheada es
 * una revocación que no ocurre.
 */
final class CacheDeRespuesta
{
    /**
     * Diez segundos, y el número sale de la forma de la curva, no del gusto.
     *
     * Lo que se ahorra con un TTL de `t` segundos es todo menos `60/t` cálculos
     * por minuto, sea cual sea el volumen. A mil peticiones por minuto —la cola
     * del sábado a las nueve— eso es: 5 s ahorra el 98.8 %, 10 s el 99.4 %,
     * 30 s el 99.8 %, 60 s el 99.9 %. Es decir, TODO el ahorro está en los
     * primeros segundos y de ahí en adelante la curva es plana: estirar el TTL
     * no compra prácticamente nada más.
     *
     * Y lo que sí cuesta estirarlo se paga entero en frescura. Un comercio que
     * se queda sin mofongo lo desactiva en su panel y quiere que se note ya;
     * diez segundos es menos de lo que tarda alguien en levantar la vista del
     * teléfono y llegar al puesto, así que el plato que se acabó desaparece de
     * la pantalla antes de que su lector llegue al mostrador. Un minuto no lo
     * haría, y no habría comprado nada a cambio.
     *
     * El precio de esta decisión, dicho sin adornos: la lista de comercios
     * puede ir hasta diez segundos por detrás de una suspensión. La CARTA de
     * ese comercio no —esa la corta la puerta, que no se cachea—, así que lo
     * que se retrasa es el nombre en una lista, no el acceso.
     */
    private const SEGUNDOS = 10;

    /**
     * Los tres endpoints, como constante y no como cadena suelta en cada
     * controlador: la llave de una caché escrita a mano en tres sitios es una
     * errata a un carácter de distancia de servir la carta de otro.
     */
    public const MANIFIESTO = 'manifiesto';

    public const COMERCIOS = 'comercios';

    public const MENU = 'menu';

    /**
     * El cuerpo de este endpoint para este evento, calculándolo solo si no
     * está.
     *
     * @param  Closure(): array<string, mixed>  $construir
     * @return array<string, mixed>
     */
    public static function recordar(string $endpoint, int $evento, ?int $comercio, Closure $construir): array
    {
        /** @var array<string, mixed> $cuerpo */
        $cuerpo = Cache::remember(self::llave($endpoint, $evento, $comercio), self::SEGUNDOS, $construir);

        return $cuerpo;
    }

    /**
     * Tirar lo guardado de un endpoint. Se llama desde donde se ESCRIBE, no
     * desde donde se lee.
     *
     * QUÉ INVALIDA Y QUÉ NO. Cambiar el manifiesto sí —lo hace el propio
     * modelo, ver EventAppManifest—: alguien elige un color en el panel, mira
     * el teléfono y no lo ve, y lo que concluye no es «hay una caché», es «el
     * panel no guardó». Rotar el PIN de un comercio no, porque no aparece en
     * ninguno de los tres cuerpos; tampoco una venta, ni una comanda, ni nada
     * del POS. Y el catálogo —un producto que se desactiva, un precio, un
     * comercio suspendido— tampoco se engancha aquí a propósito: son escrituras
     * del camino caliente del POS y del panel del comercio, y colgarles un
     * borrado de caché por cada evento en el que participa ese comercio mete
     * una consulta de la app del asistente dentro de una venta. Diez segundos
     * ya cubren ese caso, que es justo para lo que el TTL es corto.
     */
    public static function olvidar(string $endpoint, int $evento, ?int $comercio = null): void
    {
        Cache::forget(self::llave($endpoint, $evento, $comercio));
    }

    /**
     * La llave va con el ID de la FILA, nunca con el código tal como vino en la
     * URL. `bocao-26`, `BOCAO26` y `Bocao26` son el mismo evento y tienen que
     * ser la misma entrada; con el texto crudo serían tres, y quien quisiera
     * podría llenar el almacén de caché con variantes de mayúsculas de un
     * código válido y dejar la caché sin servir para nada — que es el mismo
     * fallo que el limitador tenía con la IP, movido de sitio.
     */
    private static function llave(string $endpoint, int $evento, ?int $comercio): string
    {
        return 'event-app:'.$endpoint.':'.$evento.($comercio === null ? '' : ':'.$comercio);
    }
}
