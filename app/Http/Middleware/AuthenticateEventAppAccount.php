<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\EventApp\Models\EventAppSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de la cuenta del asistente: el token de la app, y nada más.
 *
 * Es el patrón de AuthenticateKdsDevice y no Sanctum, por las dos trampas
 * que allí están documentadas y medidas: `guard => ['web']` haría que una
 * sesión web abierta en el navegador del teléfono autenticase a ESA persona
 * sin código ninguno, y `sanctum:prune-expired` borra por `created_at`, así
 * que un token «de larga vida» moriría a los quince días en silencio. Aquí
 * el token vive en una tabla propia, en sha256, y se busca con una igualdad
 * indexada.
 *
 * Y NO SE LLAMA AL ContextResolver, QUE ES LA MITAD DE LA DECISIÓN. El
 * asistente es un actor de PLATAFORMA: su cuenta no pertenece a ningún
 * organizador, así que no hay tenant que resolver ni equipo de permisos que
 * fijar. Todo lo que esta puerta lee y escribe (cuenta, sesiones) vive fuera
 * de los scopes; el día que un endpoint con sesión hable además de UN evento
 * (pedidos, saldo), el contexto lo fijará el evento de la URL — como en la
 * puerta pública — y no esta identidad.
 *
 * Se revalida TODO en cada petición: que el token exista, que no esté
 * revocado y que la cuenta siga existiendo. Es la doctrina de los tokens de
 * larga vida de la casa — sin revalidación serían tokens eternos, y aquí,
 * además, la única revocación que existe: borrar la cuenta tiene que apagar
 * todos sus teléfonos en la petición siguiente.
 */
class AuthenticateEventAppAccount
{
    /**
     * Cada cuánto se persiste el «sigo viva» de la sesión. El mismo freno de
     * escritura que la batería del KDS y por el mismo motivo: miles de
     * teléfonos consultando su cuenta serían miles de UPDATE por minuto para
     * un dato que solo distingue una sesión viva de una abandonada.
     */
    private const SEGUNDOS_ENTRE_LATIDOS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $claro = $request->bearerToken();

        if ($claro === null || $claro === '') {
            return $this->rechazo();
        }

        $sesion = EventAppSession::query()
            ->where('token_hash', hash('sha256', $claro))
            ->first();

        // Un solo rechazo para el token que no existe, el revocado y el de
        // la cuenta borrada: la app hace lo mismo con los tres —olvida su
        // token y vuelve al estado anónimo— y distinguirlos solo contaría
        // cosas a quien anda probando tokens.
        if ($sesion === null || $sesion->estaRevocada()) {
            return $this->rechazo();
        }

        $cuenta = $sesion->cuenta()->first();

        if ($cuenta === null) {
            return $this->rechazo();
        }

        $request->attributes->set('event_app_account', $cuenta);
        $request->attributes->set('event_app_session', $sesion);

        $this->latido($sesion);

        return $next($request);
    }

    /** El «sigo viva», como mucho una vez por minuto. */
    private function latido(EventAppSession $sesion): void
    {
        $ultimo = $sesion->last_used_at;

        // Sobre now() y nunca sobre $ultimo: Carbon es mutable y un
        // $ultimo->addSeconds(...) dejaría el atributo del modelo reescrito
        // en memoria con una hora inventada — la misma trampa que ya está
        // contada en el latido del KDS.
        if ($ultimo !== null && $ultimo->greaterThan(now()->subSeconds(self::SEGUNDOS_ENTRE_LATIDOS))) {
            return;
        }

        $sesion->last_used_at = now();
        $sesion->save();
    }

    private function rechazo(): JsonResponse
    {
        // JSON siempre y sin mirar expectsJson: al otro lado solo está la
        // app. El código es EXACTAMENTE el del contrato: la app lo usa para
        // borrar su token guardado y seguir donde estaba, sin perder la
        // pantalla — la sesión es un accesorio, no el suelo.
        return response()->json([
            'code' => 'sesion_invalida',
            'message' => 'Tu sesión ya no vale. Entra de nuevo con un código.',
        ], 401);
    }
}
