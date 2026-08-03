<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\EventManagement\Enums\VendorStatus;
use App\Domains\EventManagement\Models\Event;
use App\Domains\EventManagement\Models\Vendor;
use App\Domains\EventManagement\VendorContext;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Domains\Operations\Models\OperatingUnit;
use App\Domains\Platform\Enums\TenantStatus;
use App\Domains\Tenancy\ContextResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de la tablet. Quien llama no es un usuario: es un sitio —la
 * pantalla de la ventanilla del puesto norte— que se enroló una vez y desde
 * entonces trae un token propio en cada polling.
 *
 * POR QUÉ ESTE MIDDLEWARE EXISTE Y NO SE REUTILIZA EL DEL POS. Es la trampa
 * más cara de todo el KDS y merece quedar escrita. SetTenantContext y
 * EnsurePosCapability están tipados a App\Models\User: llaman a
 * $request->user(), que para un token de dispositivo devuelve null. Con null,
 * ContextResolver::forUser() LIMPIA los tres estados y vuelve sin abortar —
 * es lo correcto para un visitante anónimo del panel—. La petición sigue
 * viva, TenantScope falla cerrado y emite `where 1 = 0`, y la tablet recibe
 * un 200 con el tablero VACÍO. Cero excepciones, cero logs, cero pistas: una
 * cocina que jura que no le entran comandas y un servidor que jura que todo
 * va bien. Por eso aquí no se monta auth:sanctum ni se reaprovecha nada de
 * la cadena del POS.
 *
 * (Sanctum tampoco valdría aunque el tipo cuadrara: config/sanctum.php deja
 * 'guard' => ['web'], así que una sesión web abierta en la tablet
 * autenticaría a ESA persona sin PIN, y sanctum:prune-expired borra por
 * created_at ignorando expires_at, o sea que todas las tabletas morirían a
 * los quince días en silencio.)
 *
 * Y se revalida TODO en CADA petición —cuenta, comercio, puesto, evento—, no
 * solo al enrolar. Es la misma doctrina de EnsurePosCapability: un token de
 * larga vida sin revalidación es un token eterno, y al comercio al que
 * echaron del evento el viernes hay que apagarle la pantalla el sábado.
 */
class AuthenticateKdsDevice
{
    /**
     * Cada cuánto se guarda el «sigo viva». Con veinte tabletas preguntando
     * cada tres segundos, escribir en cada petición serían cuatrocientas
     * escrituras por minuto para un dato que solo sirve para pintar un punto
     * verde en el panel.
     */
    private const SEGUNDOS_ENTRE_LATIDOS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $claro = $request->bearerToken();

        if ($claro === null || $claro === '') {
            return $this->rechazo('kds_sin_token', 'Esta tablet no está enrolada. Introduce el código del comercio y el PIN del puesto.');
        }

        $device = $this->porToken($claro);

        if ($device === null || ! $this->sigueOperando($device)) {
            // Un solo rechazo para el token que no existe, el revocado y el
            // del puesto que cerraron: la tablet no puede hacer nada
            // distinto con esa información, y la app responde igual a los
            // tres — vuelve a la pantalla de alta.
            return $this->rechazo('kds_revocado', 'Esta tablet ya no puede entrar. Vuelve a activarla con el código y el PIN.');
        }

        app(ContextResolver::class)->forDevice($device);

        // El backstop explícito contra el fail-open de VendorScope. Sí, es
        // redundante con kitchen_tickets.vendor_id NOT NULL y con lo que
        // acaba de decidir forDevice — y así debe ser: lo que separa las
        // comandas de dos comercios del mismo festival no puede depender de
        // una sola línea que alguien pueda borrar sin que ningún test rojo
        // se entere.
        abort_unless(app(VendorContext::class)->check(), 403, 'El KDS opera para un comercio.');

        // Los controladores necesitan saber QUIÉN tocó: started_by_device_id
        // y ready_by_device_id salen de aquí.
        $request->attributes->set('kds_device', $device);

        $this->latido($device);

        return $next($request);
    }

    /**
     * El token viaja en claro y en la base solo vive su sha256, así que la
     * búsqueda es una igualdad indexada y no un bucle de bcrypt.
     *
     * withoutTenancy() porque todavía no hay cuenta: el token ES lo que dice
     * de qué cuenta es esta petición. Y runWithoutVendor() porque VendorScope
     * NO se quita con withoutTenancy(): si algo dejó un comercio colgando en
     * el contenedor —un test, un job, Octane—, un token perfectamente válido
     * de otro comercio no aparecería y la tablet vería un 401 inexplicable.
     */
    private function porToken(string $claro): ?KdsDevice
    {
        return app(VendorContext::class)->runWithoutVendor(
            fn (): ?KdsDevice => KdsDevice::query()->withoutTenancy()
                ->where('token_hash', hash('sha256', $claro))
                ->first(),
        );
    }

    /**
     * Lo que hay que seguir siendo para que la pantalla siga encendida. Es
     * la misma lista que comprueba EnrollKdsDevice al dar de alta, repetida
     * aquí a conciencia: allí decide si se entrega el token, aquí si el
     * token todavía vale, y son dos preguntas distintas que solo hoy tienen
     * la misma respuesta.
     *
     * Todas las consultas van sin cuenta activa porque el contexto aún no se
     * ha fijado — fijarlo antes de saber si el dispositivo puede entrar sería
     * abrir la puerta para después mirar quién llamaba.
     */
    private function sigueOperando(KdsDevice $device): bool
    {
        if ($device->estaRevocada()) {
            return false;
        }

        $tenant = $device->tenant()->first();

        if ($tenant === null || $tenant->status === TenantStatus::Suspended) {
            return false;
        }

        $vendor = Vendor::query()->withoutTenancy()->find($device->vendor_id);

        if ($vendor === null || $vendor->status !== VendorStatus::Active) {
            return false;
        }

        $unidad = OperatingUnit::query()->withoutTenancy()->find($device->operating_unit_id);

        if ($unidad === null || $unidad->status !== OperatingUnitStatus::Active) {
            return false;
        }

        // RemoveVendorFromEvent no borra los puestos del comercio que sale
        // del evento: los deja en Closed, así que la línea de arriba ya lo
        // cubre. Esta mira el otro final, el que no cierra puestos uno a
        // uno: el festival que terminó y se llevó por delante todo lo suyo.
        $eventId = $unidad->event_id;

        if ($eventId === null) {
            return true;
        }

        $evento = Event::query()->withoutTenancy()->find($eventId);

        return $evento !== null && ! $evento->status->isFinished();
    }

    /**
     * El «sigo viva» del panel. Se escribe con save() y no con saveQuietly()
     * para que el guard de identidad de KdsDevice siga corriendo: si algún
     * día alguien mete otra columna en este camino, que reviente aquí.
     */
    private function latido(KdsDevice $device): void
    {
        $ultimo = $device->last_seen_at;

        // El umbral se calcula sobre now() y NUNCA sobre $ultimo: Carbon es
        // mutable, así que un $ultimo->addSeconds(...) reescribiría el
        // atributo del modelo en memoria y dejaría la fila sucia con una
        // hora inventada, lista para que el siguiente save() de cualquiera
        // la persistiera.
        if ($ultimo !== null && $ultimo->greaterThan(now()->subSeconds(self::SEGUNDOS_ENTRE_LATIDOS))) {
            return;
        }

        $device->last_seen_at = now();
        $device->save();
    }

    private function rechazo(string $code, string $message): JsonResponse
    {
        // JSON siempre y sin mirar expectsJson: al otro lado de esta puerta
        // no hay ninguna pantalla HTML, solo la app de la tablet.
        return response()->json(['code' => $code, 'message' => $message], 401);
    }
}
