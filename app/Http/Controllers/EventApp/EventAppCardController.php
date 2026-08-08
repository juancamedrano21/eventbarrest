<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\EventApp\Actions\GuardarTarjetaDelAsistente;
use App\Domains\EventApp\Actions\OlvidarTarjetaDelAsistente;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppCard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Las tarjetas guardadas del asistente: listar, guardar, marcar y quitar.
 *
 * No extiende `EventAppController` por lo mismo que no lo extiende el de la
 * cuenta: aquello es la maquinaria de ETag y caché de las respuestas PÚBLICAS
 * por evento, y aquí nada es público ni cacheable — cada respuesta es de una
 * persona y cambiaría en cada petición. Tampoco pasa por
 * `ResolveEventAppContext`: la tarjeta cuelga de la cuenta, que es de
 * plataforma, y en estas rutas no hay evento del que resolver nada.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TODA CONSULTA ARRANCA DE LA CUENTA DEL TOKEN. SIN EXCEPCIÓN.
 * ─────────────────────────────────────────────────────────────────────────
 * Aquí no hay `TenantScope` que falle cerrado ni nada equivalente: estas
 * tablas viven fuera de los scopes a propósito (son de plataforma), así que
 * el aislamiento entre asistentes lo sostiene ÚNICAMENTE que cada consulta
 * salga de `$cuenta->id`. Un `EventAppCard::find($id)` suelto en cualquiera
 * de estos métodos —la forma natural de escribirlo— dejaría a cualquiera
 * mirar, marcar o BORRAR la tarjeta de otro sabiendo un número. Por eso el
 * acceso pasa siempre por `deLaCuenta()` y por eso hay tests negativos.
 */
class EventAppCardController extends Controller
{
    /** GET /api/event-app/cuenta/tarjetas */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listado($this->cuenta($request)));
    }

    /**
     * POST /api/event-app/cuenta/tarjetas — guardar una tarjeta.
     *
     * El `transient_token` lo produce el webview de Unified Checkout, que es
     * trabajo del cuarto slice: aquí se recibe y se manda tal cual, sin que
     * ningún dato de tarjeta toque este servidor (alcance SAQ A).
     */
    public function store(Request $request, GuardarTarjetaDelAsistente $guardar): JsonResponse
    {
        $data = $request->validate([
            'transient_token' => ['required', 'string', 'max:4096'],
            // `nullable` y no `required`: la ausencia y el `false` son la
            // misma cosa —no consintió— y tienen que contestar lo mismo. Con
            // `required` la ausencia caería en el 422 estándar de Laravel,
            // sin `code`, y la app no podría distinguir «falta el
            // consentimiento» de un cuerpo mal formado.
            'consentimiento' => ['nullable', 'boolean'],
        ]);

        // ANTES de salir a la red, siempre. Cobrar una verificación a alguien
        // que no dijo que sí y decírselo después no se arregla con un 422.
        if ($request->boolean('consentimiento') !== true) {
            return response()->json([
                'code' => 'consentimiento_requerido',
                'message' => 'Necesitamos que aceptes guardar la tarjeta antes de guardarla.',
            ], 422);
        }

        $alta = $guardar(
            $this->cuenta($request),
            $data['transient_token'],
            // La IP tal como la declara quien llama: con trustProxies('*') la
            // escribe el propio cliente. Se registra por lo que vale, que no
            // es prueba, y así está anotado en la migración.
            $request->ip(),
        );

        if ($alta->esIncierta) {
            // EL 409 QUE NO ES UN RECHAZO. La app tiene texto propio para
            // esto —«no pudimos confirmarlo, revisa tus tarjetas en un
            // momento»— y NO enseña un botón de reintentar: si el cobro de
            // verificación llegó a hacerse, el segundo intento lo repite.
            return response()->json([
                'code' => 'verificacion_incierta',
                'message' => 'No pudimos confirmar la tarjeta. Revisa tus tarjetas en un momento antes de volver a intentarlo.',
            ], 409);
        }

        if (! $alta->fueGuardada()) {
            return response()->json([
                'code' => 'tarjeta_rechazada',
                'motivo' => $alta->motivo,
                'message' => $alta->motivo,
            ], 422);
        }

        /** @var EventAppCard $tarjeta */
        $tarjeta = $alta->tarjeta;

        return response()->json(
            ['tarjeta' => $this->publicada($tarjeta)] + $this->reloj(),
            201,
        );
    }

    /**
     * PATCH /api/event-app/cuenta/tarjetas/{tarjeta} — marcarla por defecto.
     *
     * Devuelve LA LISTA ENTERA y no la tarjeta: marcar una desmarca otra, y
     * si la respuesta fuera solo la marcada la app se quedaría con dos
     * tarjetas por defecto en pantalla hasta la siguiente recarga.
     *
     * `accepted` en la validación hace que `por_defecto: false` sea un 422 de
     * cuerpo mal formado y no un no-op silencioso. Desmarcar sin marcar otra
     * dejaría a la cuenta con tarjetas y ninguna elegida, que es el estado
     * que ni la app ni el servidor saben resolver; el gesto que existe es
     * marcar otra.
     *
     * ─────────────────────────────────────────────────────────────────────
     * ES UN `SET`, NO UN INTERRUPTOR: MARCAR LO YA MARCADO NO CAMBIA NADA.
     * ─────────────────────────────────────────────────────────────────────
     * La primera versión cargaba la elegida FUERA de la transacción, desmarcaba
     * todas con un `update` masivo —que no refresca el modelo en memoria— y
     * después hacía `$elegida->is_default = true; $elegida->save()`. Sobre la
     * tarjeta que YA era la de por defecto, Eloquent comparaba con su
     * `$original` (que seguía diciendo `true`), `getDirty()` salía vacío y el
     * `save()` no emitía ningún UPDATE: la cuenta se quedaba con tarjetas y
     * NINGUNA elegida, contestando 200. Y es pegajoso —guardar otra no la
     * elige, borrar una no asciende heredera— así que el gesto más normal que
     * hay (tocar la tarjeta que ya está seleccionada) rompía la cuenta para
     * siempre.
     *
     * Por eso las dos escrituras van por el constructor de consultas y no por
     * el modelo: no hay estado en memoria que pueda estar viejo. Y el `update`
     * masivo deja FUERA a la elegida, con lo que la ventana de «ninguna por
     * defecto» deja de existir incluso dentro de la transacción.
     */
    public function actualizar(Request $request, int $tarjeta): JsonResponse
    {
        $request->validate([
            'por_defecto' => ['required', 'boolean', 'accepted'],
        ]);

        $cuenta = $this->cuenta($request);
        $elegida = $this->deLaCuenta($cuenta, $tarjeta);

        DB::transaction(function () use ($cuenta, $elegida): void {
            EventAppCard::query()
                ->where('event_app_account_id', $cuenta->id)
                ->where('is_default', true)
                ->whereKeyNot($elegida->getKey())
                ->update(['is_default' => false]);

            EventAppCard::query()
                ->whereKey($elegida->getKey())
                ->update(['is_default' => true]);
        });

        return response()->json($this->listado($cuenta));
    }

    /**
     * DELETE /api/event-app/cuenta/tarjetas/{tarjeta} → 204.
     *
     * El borrado va PRIMERO a la bóveda; si allá falla, aquí no se borra y la
     * petición revienta. El porqué largo está en
     * `OlvidarTarjetaDelAsistente`, que es quien manda el orden.
     */
    public function borrar(Request $request, int $tarjeta, OlvidarTarjetaDelAsistente $olvidar): Response
    {
        $olvidar($this->deLaCuenta($this->cuenta($request), $tarjeta));

        return response()->noContent();
    }

    /**
     * La tarjeta pedida, SOLO si es de esta cuenta.
     *
     * El 404 es el mismo para «no existe» y «es de otro» a propósito: la
     * respuesta no puede contar si un id existe en la plataforma. Es el mismo
     * criterio que el `evento_desconocido` de la puerta pública.
     */
    private function deLaCuenta(EventAppAccount $cuenta, int $id): EventAppCard
    {
        $tarjeta = EventAppCard::query()
            ->where('event_app_account_id', $cuenta->id)
            ->whereKey($id)
            ->first();

        if ($tarjeta === null) {
            abort(response()->json([
                'code' => 'tarjeta_desconocida',
                'message' => 'Esa tarjeta ya no está en tu cuenta.',
            ], 404));
        }

        return $tarjeta;
    }

    /**
     * @return array{tarjetas: list<array<string, mixed>>, server_time: string}
     */
    private function listado(EventAppAccount $cuenta): array
    {
        $tarjetas = EventAppCard::query()
            ->where('event_app_account_id', $cuenta->id)
            ->enOrdenDeApp()
            ->get()
            ->map(fn (EventAppCard $tarjeta): array => $this->publicada($tarjeta))
            ->all();

        // La lista vacía es una respuesta válida —una cuenta sin tarjetas—,
        // no un error: la app pinta «todavía no guardaste ninguna».
        return ['tarjetas' => $tarjetas] + $this->reloj();
    }

    /**
     * La forma del contrato. `vencida` la calcula el SERVIDOR: de eso depende
     * que un cobro falle y el reloj del teléfono lo cambia quien lo lleva.
     *
     * @return array<string, mixed>
     */
    private function publicada(EventAppCard $tarjeta): array
    {
        return [
            'id' => $tarjeta->id,
            'marca' => $tarjeta->brand->value,
            'ultimos4' => $tarjeta->last4,
            'vence_mes' => $tarjeta->exp_month,
            'vence_ano' => $tarjeta->exp_year,
            'por_defecto' => $tarjeta->is_default,
            'vencida' => $tarjeta->estaVencida(),
        ];
    }

    /**
     * La hora del servidor, en el huso del negocio y no en UTC, igual que en
     * la puerta pública: el teléfono no puede fiarse del suyo.
     *
     * @return array{server_time: string}
     */
    private function reloj(): array
    {
        return ['server_time' => now()->setTimezone((string) config('app.business_timezone'))->toIso8601String()];
    }

    /** La cuenta que dejó puesta la puerta. */
    private function cuenta(Request $request): EventAppAccount
    {
        $cuenta = $request->attributes->get('event_app_account');

        // Detrás de AuthenticateEventAppAccount esto está siempre; el
        // instanceof es para el analizador, que solo ve un mixed.
        abort_unless($cuenta instanceof EventAppAccount, 401);

        return $cuenta;
    }
}
