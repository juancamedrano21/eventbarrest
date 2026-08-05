<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\EventManagement\Models\Event;
use App\Http\Controllers\Controller;
use Carbon\CarbonInterface;
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
 */
abstract class EventAppController extends Controller
{
    /**
     * `no-cache` no significa «no lo guardes»: significa «guárdalo si
     * quieres, pero pregunta SIEMPRE antes de servirlo», que es justo lo que
     * hace falta — lo que ahorra el payload aquí es el 304, no la caché. Sin
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
     * @param  array<string, mixed>  $cuerpo
     */
    protected function responder(Request $request, array $cuerpo): Response
    {
        // Débil (W/) porque lo que se compara es el SIGNIFICADO de la
        // respuesta, no el byte: dos manifiestos idénticos son el mismo
        // manifiesto aunque server_time los separe.
        $etag = 'W/"'.sha1((string) json_encode($cuerpo)).'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(304)
                ->header('ETag', $etag)
                ->header('Cache-Control', self::CACHE);
        }

        return response()
            ->json($cuerpo + ['server_time' => $this->ahora()])
            ->header('ETag', $etag)
            ->header('Cache-Control', self::CACHE);
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
