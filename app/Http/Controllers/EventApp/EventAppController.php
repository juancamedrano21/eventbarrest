<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\EventApp\Support\CacheDeRespuesta;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Http\Controllers\Controller;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que comparten los tres endpoints de la app: cómo se contesta.
 *
 * EL ETag SE CALCULA SIN server_time, Y ESE ES EL DETALLE QUE LO DECIDE
 * TODO. Es la misma trampa que ya mordió en el KDS, y aquí la factura sería
 * mayor: al otro lado no hay veinte tabletas en el wifi del recinto, hay
 * miles de teléfonos con datos móviles saturados. La hora del servidor viaja
 * en la respuesta porque el teléfono no puede fiarse del suyo, pero si
 * entrara en el hash el ETag cambiaría cada segundo, el 304 no ocurriría
 * JAMÁS y cada arranque de la app se descargaría el manifiesto y la carta
 * entera. Se hashea el cuerpo, se añade la hora después.
 *
 * Está en un solo sitio a propósito: tres copias de esta regla son tres
 * sitios donde alguien puede meter `server_time` dentro del hash.
 *
 * Y AQUÍ VIVE TAMBIÉN LA CACHÉ DE RESPUESTA, por la misma razón: el ETag
 * ahorra red y no ahorra servidor —un 304 hace exactamente las mismas
 * consultas que un 200—, y esta puerta es pública, anónima y sin ningún techo
 * de volumen. Lo que la sostiene es que la respuesta se calcule una vez por
 * evento y no una por teléfono, y eso solo funciona si el ETag sale del cuerpo
 * ya cacheado. Las dos cosas tienen que decidirse en la misma línea, así que
 * están en el mismo método. El porqué del TTL y de qué invalida, en
 * CacheDeRespuesta.
 */
abstract class EventAppController extends Controller
{
    /**
     * `no-cache` no significa «no lo guardes»: significa «guárdalo si
     * quieres, pero pregunta SIEMPRE antes de servirlo», que es justo lo que
     * hace falta — lo que ahorra el payload aquí es el 304, no la caché DEL
     * CLIENTE. Esta cabecera habla de la caché de quien pregunta y no dice
     * nada de la del servidor, que es otra cosa y está más abajo. Sin
     * ninguna directiva, una respuesta con ETag y sin caducidad es cacheable
     * por heurística, y el proxy del operador móvil podría servir la carta de
     * ayer a un asistente que está mirando el precio de hoy.
     *
     * `private` lo fija el contrato. Este cuerpo es idéntico para todo el
     * mundo, así que `public` sería defendible y hasta mejor —una caché
     * compartida delante ahorraría miles de peticiones—, pero eso es una
     * decisión de despliegue que se toma con el borde ya acotado, no ahora.
     */
    private const CACHE = 'no-cache, private';

    /**
     * El cuerpo llega en un cierre y NO calculado, que es lo que permite no
     * calcularlo. Un array ya construido obligaría a hacer las consultas antes
     * de saber si hacen falta, y entonces la caché ahorraría memoria en vez de
     * base de datos, que es lo contrario de lo que hace falta aquí.
     *
     * El ETag sale del cuerpo cacheado, y de ahí el ahorro que faltaba: un 304
     * no vuelve a consultar el catálogo, solo lo compara. Sigue siendo estable
     * entre servidores porque se deriva del contenido y no de quién lo sirvió:
     * dos nodos con cachés separadas calculan el mismo hash sobre los mismos
     * datos, así que un balanceador no puede invalidar el ETag de un teléfono
     * mandándolo al otro nodo.
     *
     * @param  Closure(): array<string, mixed>  $construir
     */
    protected function responder(Request $request, string $endpoint, Closure $construir): Response
    {
        $cuerpo = $this->publicar(CacheDeRespuesta::recordar(
            $endpoint,
            $this->evento($request)->id,
            $this->comercioId($request),
            $construir,
        ));

        // Débil (W/) porque lo que se compara es el SIGNIFICADO de la
        // respuesta, no el byte: dos manifiestos idénticos son el mismo
        // manifiesto aunque server_time los separe.
        $etag = 'W/"'.sha1((string) json_encode($cuerpo)).'"';

        if ($this->yaLoTiene($request, $etag)) {
            return response()->noContent(304)
                ->header('ETag', $etag)
                ->header('Cache-Control', self::CACHE);
        }

        return response()
            ->json($cuerpo + ['server_time' => $this->ahora()])
            ->header('ETag', $etag)
            ->header('Cache-Control', self::CACHE);
    }

    /**
     * El último retoque de FORMA, ya fuera de la caché. Por defecto no hace
     * nada; lo sobreescribe quien tenga una decisión de cómo se escribe el
     * JSON que no cabe en un array plano.
     *
     * EXISTE PORQUE EN LA CACHÉ SOLO PUEDEN VIAJAR DATOS PLANOS, y eso no es
     * una preferencia: `config/cache.php` fija `serializable_classes => false`
     * —deserializar clases arbitrarias es un vector conocido—, así que
     * cualquier objeto que se guarde vuelve convertido en
     * `__PHP_Incomplete_Class`. Medido con el store `database`: el `(object)`
     * de `textos` volvía como `{"__PHP_Incomplete_Class_Name":"stdClass"}`,
     * que no es solo un ETag distinto en cada petición —el 304 dejaba de
     * ocurrir—, es basura servida a la app en el campo que el contrato promete
     * como diccionario. Y no se veía en los tests, que corren con el store
     * `array`, donde nada se serializa.
     *
     * Por eso el reparto: la caché guarda QUÉ se responde y este método decide
     * CÓMO se escribe. Un objeto construido aquí ya no pasa por ningún
     * serializador.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    protected function publicar(array $cuerpo): array
    {
        return $cuerpo;
    }

    /**
     * Si lo que el cliente dice tener es esta misma representación.
     *
     * LA COMPARACIÓN DE UN GET CONDICIONAL ES DÉBIL (RFC 9110 §8.8.3.2), y
     * eso no es purismo: la comparación de cadena exacta que había aquí
     * fallaba con dos formas que un cliente HTTP manda a diario. Un `*`, que
     * es como revalida quien pregunta «¿sigue existiendo esto?» sin recordar
     * qué tenía, y el mismo ETag sin el prefijo `W/`, que es lo que deja un
     * intermediario o un cliente que normaliza. Las dos se saldaban con el
     * cuerpo entero de vuelta, que es exactamente el ahorro que el ETag venía
     * a hacer y el que más falta hace en la red saturada de un recinto.
     *
     * `getETags()` ya parte la cabecera por comas, así que la lista —la forma
     * normal de mandarlo cuando el cliente guarda varias— entra sola.
     */
    private function yaLoTiene(Request $request, string $etag): bool
    {
        $candidatos = $request->getETags();

        // El comodín casa con cualquier representación que exista, y aquí
        // siempre existe: si hemos llegado a responder, hay algo que servir.
        if (in_array('*', $candidatos, true)) {
            return true;
        }

        $nuestro = $this->opaco($etag);

        foreach ($candidatos as $candidato) {
            if ($this->opaco($candidato) === $nuestro) {
                return true;
            }
        }

        return false;
    }

    /** El ETag sin su marca de débil: lo que compara la comparación débil. */
    private function opaco(string $etag): string
    {
        return (string) preg_replace('/^W\//i', '', trim($etag));
    }

    /** El evento que dejó puesto la puerta. */
    protected function evento(Request $request): Event
    {
        $evento = $request->attributes->get('event_app_event');

        // Detrás de ResolveEventAppContext esto está siempre; el instanceof
        // es para el analizador, que solo ve un mixed saliendo de aquí.
        abort_unless($evento instanceof Event, 404);

        return $evento;
    }

    /**
     * El comercio de la URL, si este endpoint lleva uno. Entra en la llave de
     * la caché porque dos comercios del mismo festival tienen cartas distintas
     * y compartir entrada sería servir la del vecino — el mismo agujero que
     * cierra el backstop de VendorScope, un piso más arriba.
     */
    private function comercioId(Request $request): ?int
    {
        $comercio = $request->attributes->get('event_app_vendor');

        return $comercio instanceof Vendor ? $comercio->id : null;
    }

    /**
     * Las horas salen en la zona del negocio y no en UTC. La app las enseña
     * tal cual —«empieza a las 8:00»— y un festival de Santo Domingo que
     * abriera a medianoche UTC diría que empieza a las cuatro de la tarde.
     */
    protected function fecha(CarbonInterface $fecha): string
    {
        return $fecha->copy()->setTimezone((string) config('app.business_timezone'))->toIso8601String();
    }

    private function ahora(): string
    {
        return now()->setTimezone((string) config('app.business_timezone'))->toIso8601String();
    }
}
