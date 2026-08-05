<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\EventApp\Actions\IssueEventPublicCode;
use App\Domains\EventManagement\Enums\EventStatus;
use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\EventVendor;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Tenancy\ContextResolver;
use App\Domains\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de la app del asistente. Quien llama no es nadie: un teléfono
 * anónimo, sin cuenta y sin token, que solo sabe el código de su festival
 * porque lo lleva compilado dentro.
 *
 * POR QUÉ EXISTE ESTE MIDDLEWARE Y NO SE MONTA auth:sanctum NI SE REUTILIZA
 * NADA DEL POS. Es la misma trampa que ya se pagó en el KDS, escrita otra
 * vez porque aquí es todavía más silenciosa. SetTenantContext llama a
 * `$request->user()`, que sin token devuelve null; con null,
 * ContextResolver::forUser() limpia el contexto y vuelve SIN abortar —es lo
 * correcto para un visitante anónimo del panel—. La petición sigue viva,
 * TenantScope falla cerrado y emite `where 1 = 0`, y el teléfono recibe un
 * 200 con el manifiesto de fábrica y CERO comercios. Sin excepción, sin log
 * y sin pista: un festival entero de gente mirando una pantalla que dice que
 * no hay dónde comer, y un servidor convencido de que todo va bien.
 *
 * Aquí el contexto sale del EVENTO de la URL, y lo resuelve
 * ContextResolver::forEvent() para que la regla de «qué cuenta opera» siga
 * viviendo en un solo archivo.
 *
 * Y se revalida TODO en CADA petición —cuenta, evento, comercio—, como en la
 * puerta del KDS: aquí no hay ni token que revocar, así que lo único que
 * apaga la app de un comercio suspendido es que se le vuelva a preguntar.
 */
class ResolveEventAppContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $evento = $this->evento($request);

        if ($evento === null) {
            return $this->eventoDesconocido();
        }

        app(ContextResolver::class)->forEvent($evento);

        // La cuenta suspendida sale por aquí: forEvent() no la fija y sin
        // cuenta no hay nada que servir. Se contesta lo mismo que a un
        // código inventado —el asistente no puede hacer nada distinto con
        // esa información, y quien prueba códigos a mano tampoco.
        if (! app(TenantContext::class)->check()) {
            return $this->eventoDesconocido();
        }

        $request->attributes->set('event_app_event', $evento);

        if ($request->route('comercio') !== null) {
            $comercio = $this->comercio($request, $evento);

            if ($comercio === null) {
                return $this->comercioDesconocido();
            }

            app(VendorContext::class)->set($comercio);

            // El backstop explícito contra el fail-open de VendorScope. Es
            // redundante con la línea de arriba, y así debe ser: lo que
            // separa la carta de dos comercios del mismo festival no puede
            // depender de una sola línea que alguien pueda borrar sin que
            // ningún test rojo se entere. El controlador lo repite otra vez.
            abort_unless(app(VendorContext::class)->check(), 403, 'La carta se sirve siempre para un comercio.');

            $request->attributes->set('event_app_vendor', $comercio);
        }

        return $next($request);
    }

    /**
     * El evento del código, si es uno que el público puede ver.
     *
     * Sin cuenta activa —todavía no la hay: el evento ES lo que dice de qué
     * cuenta es esta petición—, así que la consulta va con withoutTenancy()
     * sobre el índice único global de public_code.
     *
     * Un borrador NO se sirve: es un evento que su organizador aún está
     * montando y cuyo nombre puede ser cualquier cosa. Uno cerrado o
     * liquidado SÍ, y esa es la decisión que merece explicarse: el festival
     * termina un domingo a las dos de la mañana y seis mil teléfonos siguen
     * teniendo la app instalada. Apagar la puerta al cerrar convertiría todas
     * esas pantallas en un error; dejarla encendida las deja enseñando la
     * carta de un festival que ya pasó, con `estado` diciendo la verdad para
     * que la app decida qué hacer con ella.
     */
    private function evento(Request $request): ?Event
    {
        $codigo = IssueEventPublicCode::normalizar($request->route('codigo'));

        if ($codigo === '') {
            return null;
        }

        $evento = Event::query()->withoutTenancy()
            ->where('public_code', $codigo)
            ->first();

        return $evento !== null && $evento->status !== EventStatus::Draft ? $evento : null;
    }

    /**
     * El comercio de la URL, PERO SOLO SI ES DE ESTE EVENTO.
     *
     * Las tres condiciones son tres agujeros distintos y ninguna sobra:
     *
     * - La cuenta la pone TenantScope, ya con el evento resuelto: un id de
     *   otro organizador simplemente no existe para esta consulta.
     * - La PARTICIPACIÓN es la que cierra el agujero de verdad, y es el único
     *   sitio donde se cierra. Un organizador con dos festivales tiene todos
     *   sus comercios en la misma cuenta, así que sin esta comprobación la
     *   app de un evento leería la carta de un comercio del OTRO cambiando un
     *   número en la URL: mismo tenant, consulta perfectamente válida, 200.
     * - Y el estado, porque un comercio suspendido a media tarde tiene que
     *   desaparecer de la app en la siguiente petición, no cuando alguien se
     *   acuerde de borrarlo.
     *
     * 404 y no 403 a propósito, como en el KDS: lo que no es de este evento
     * no existe, y así probar ids a mano tampoco sirve para averiguar qué
     * comercios tiene el organizador en sus otros festivales.
     */
    private function comercio(Request $request, Event $evento): ?Vendor
    {
        $id = filter_var($request->route('comercio'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return null;
        }

        $comercio = Vendor::query()->find($id);

        if ($comercio === null || $comercio->status !== VendorStatus::Active) {
            return null;
        }

        $participa = EventVendor::query()
            ->where('event_id', $evento->id)
            ->where('vendor_id', $comercio->id)
            ->exists();

        return $participa ? $comercio : null;
    }

    /**
     * JSON siempre y sin mirar expectsJson: al otro lado de esta puerta no
     * hay ninguna pantalla HTML, solo la app del asistente.
     */
    private function eventoDesconocido(): JsonResponse
    {
        return response()->json([
            'code' => 'evento_desconocido',
            'message' => 'No encontramos este evento.',
        ], 404);
    }

    private function comercioDesconocido(): JsonResponse
    {
        return response()->json([
            'code' => 'comercio_desconocido',
            'message' => 'Este comercio no está en el evento.',
        ], 404);
    }
}
