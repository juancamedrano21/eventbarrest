<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventApp;

use App\Domains\EventApp\Actions\IssueLoginCode;
use App\Domains\EventApp\Actions\OlvidarTarjetasDeLaCuenta;
use App\Domains\EventApp\Actions\RedeemLoginCode;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppLoginCode;
use App\Domains\EventApp\Models\EventAppSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La cuenta del asistente: pedir código, entrar, perfil, salir y borrar.
 *
 * No extiende EventAppController a propósito: aquello es la maquinaria de
 * ETag y caché de las respuestas PÚBLICAS por evento, y aquí nada es público
 * ni cacheable — cada respuesta es de una persona. Tampoco pasa por
 * ResolveEventAppContext: la cuenta es de la plataforma, no de un evento, y
 * en estas rutas no hay código de evento del que resolver nada.
 */
class EventAppAccountController extends Controller
{
    /**
     * Códigos por buzón y por hora antes del 429. Seis, pensado desde el
     * asistente impaciente y no desde el atacante: pide uno, el correo
     * tarda, toca «reenviar» dos o tres veces, se equivoca de app y vuelve a
     * pedir — sigue sin llegar a seis en una hora. Para el spam a un buzón
     * ajeno, seis correos por hora es un goteo sin amplificación posible.
     *
     * ES POR DESTINO (email normalizado) Y NO POR IP, porque con
     * trustProxies('*') la IP la escribe quien llama: un cubo por IP no
     * cuenta contra quien ataca —estrena IP en cada petición— y sí contra el
     * público de un festival, que sale entero por el NAT de dos operadores.
     *
     * Y NO PUEDE DEJAR FUERA A LA PERSONA LEGÍTIMA, que es la regla de la
     * casa: este freno solo raciona EMITIR códigos nuevos; entrar con el
     * código vigente no pasa por aquí y no se raciona nunca. Lo peor que un
     * atacante consigue inundando un buzón ajeno es que su dueño espere una
     * hora para un código NUEVO — con el último que le llegó entra igual.
     */
    private const CODIGOS_POR_HORA = 6;

    /**
     * El CORTACIRCUITOS global de emisión: cuántos códigos por minuto puede
     * emitir la puerta ENTERA, sumando todos los buzones. Existe porque el
     * freno por destino no acota el VOLUMEN: un llamante que rote buzones
     * inventados siembra filas y correos sin techo, y el correo saliente
     * sin límite quema la reputación del dominio remitente — un dominio en
     * lista negra deniega el alta A TODOS durante días.
     *
     * El número se dimensiona desde el pico legítimo real, no desde el
     * atacante: doc 11 habla de más de 6.000 asistentes, y si todos se
     * registraran en la hora de puertas serían ~100 códigos por minuto.
     * Seis veces eso deja margen para las olas (la cola no llega plana) y
     * para eventos solapados. Es un cortacircuitos HOLGADO a propósito, no
     * un freno fino: si un ataque lo alcanza, el alta se degrada un minuto
     * para todos — ese es el precio, y se paga a sabiendas porque la
     * alternativa es peor por días y para todos. La ventana de UN minuto es
     * lo que hace corto el apagón: el cubo se vacía solo y el Retry-After
     * nunca pasa de 60.
     *
     * LA LLAVE ES CONSTANTE a propósito: quien ataca no elige qué cubo
     * llena — un contador que sube quien ataca sobre algo que él elige es
     * el botón de apagado dirigido que la regla de la casa prohíbe. Tumbar
     * puede tumbar el alta entera un rato; dirigirlo contra un buzón o una
     * persona concreta, no. Y como todo freno de esta puerta, raciona
     * EMITIR: entrar con un código vigente no pasa por aquí jamás.
     */
    public const EMISIONES_GLOBALES_POR_MINUTO = 600;

    public const LLAVE_GLOBAL = 'event-app-codigo-global';

    /**
     * POST /api/event-app/cuenta/codigo — 202 SIEMPRE que el email tenga
     * forma válida, exista la cuenta o no. Y no es que las dos ramas
     * contesten igual: es que NO HAY dos ramas — la cuenta no se consulta en
     * ningún punto de este camino (ver IssueLoginCode). Sin oráculo de
     * enumeración, ni por cuerpo, ni por estado, ni por reloj.
     */
    public function codigo(Request $request, IssueLoginCode $emitir): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = EventAppAccount::normalizarEmail($data['email']);

        // La llave lleva el email HASHEADO, no en crudo: el RateLimiter de
        // Laravel pasa toda llave por htmlentities, así que «josé@» y
        // «jose@» —dos buzones distintos y legales— colapsaban al MISMO
        // cubo, y josé recibía el 429 con la bandeja vacía: el único caso
        // en que este freno negaba a una persona legítima. El hash hace la
        // llave inmune a esa limpieza y, de paso, deja de guardar el correo
        // en claro en la tabla de caché.
        $llave = 'event-app-codigo:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($llave, self::CODIGOS_POR_HORA)) {
            return $this->demasiados(
                'codigo_pedido_demasiado',
                'Ya pedimos varios códigos para ese correo. Busca el último que te llegó o espera un rato.',
                RateLimiter::availableIn($llave),
            );
        }

        // El cortacircuitos va DESPUÉS del freno fino: la petición que ya
        // iba a ser rechazada por su buzón no debe llevarse el code global
        // — el suyo es «espera por TU buzón», no «la puerta está saturada».
        if (RateLimiter::tooManyAttempts(self::LLAVE_GLOBAL, self::EMISIONES_GLOBALES_POR_MINUTO)) {
            return $this->demasiados(
                'emision_saturada',
                'Estamos mandando muchos códigos ahora mismo. Espera un momento y vuelve a pedirlo.',
                RateLimiter::availableIn(self::LLAVE_GLOBAL),
            );
        }

        RateLimiter::hit($llave, 3600);
        RateLimiter::hit(self::LLAVE_GLOBAL, 60);

        $emitir($email);

        return response()->json([
            'message' => 'Si ese buzón es tuyo, tienes un código en camino.',
        ], 202);
    }

    /**
     * POST /api/event-app/cuenta/entrar — canjea el código por el token.
     *
     * El 422 es UNO para incorrecto, caducado y quemado, a propósito: tres
     * respuestas distintas dirían quién pidió código y cuándo. Y `nombre` es
     * opcional SIEMPRE, no «obligatorio si la cuenta es nueva»: exigirlo
     * según exista la cuenta convertiría la validación —que corre ANTES de
     * comprobar el código— en un oráculo de enumeración gratuito.
     */
    public function entrar(Request $request, RedeemLoginCode $canjear): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'codigo' => ['required', 'string', 'max:10'],
            'nombre' => ['nullable', 'string', 'max:120'],
        ]);

        $sesion = $canjear(
            EventAppAccount::normalizarEmail($data['email']),
            $data['codigo'],
            $data['nombre'] ?? null,
        );

        if ($sesion === null) {
            return response()->json([
                'code' => 'codigo_invalido',
                'message' => 'Ese código no vale. Pide uno nuevo y vuelve a intentarlo.',
            ], 422);
        }

        return response()->json([
            'token' => $sesion->plainToken,
            'cuenta' => $this->cuentaPublicada($sesion->cuenta),
        ]);
    }

    /** GET /api/event-app/cuenta — el perfil de quien trae el token. */
    public function perfil(Request $request): JsonResponse
    {
        return response()->json([
            'cuenta' => $this->cuentaPublicada($this->cuenta($request)),
        ]);
    }

    /** PATCH /api/event-app/cuenta — hoy solo el nombre. */
    public function actualizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
        ]);

        $cuenta = $this->cuenta($request);
        $cuenta->name = $data['nombre'];
        $cuenta->save();

        return response()->json([
            'cuenta' => $this->cuentaPublicada($cuenta),
        ]);
    }

    /**
     * POST /api/event-app/cuenta/salir — revoca ESTE token y solo este. El
     * mismo asistente con la app en dos teléfonos sigue dentro en el otro:
     * salir es un gesto del aparato, no de la cuenta.
     */
    public function salir(Request $request): Response
    {
        $sesion = $request->attributes->get('event_app_session');
        abort_unless($sesion instanceof EventAppSession, 401);

        $sesion->revoked_at = now();
        $sesion->save();

        return response()->noContent();
    }

    /**
     * DELETE /api/event-app/cuenta — borra la cuenta DE VERDAD, con todas
     * sus sesiones detrás (la foreign key las arrastra). Existe porque Apple
     * lo exige (5.1.1(v)) y porque es lo correcto.
     *
     * ESTE ENDPOINT ES EL DUEÑO DE LA DECISIÓN DE ANONIMIZAR. Hoy borra
     * fila y ya, porque nada cuelga de la cuenta que haya que conservar. El
     * día que existan pedidos o saldo, lo que cambia es ESTE método:
     * decidirá qué se anonimiza (el rastro fiscal de una venta no se borra) y
     * qué se destruye. Esa decisión no se toma hoy y no debe tomarse en
     * ningún otro sitio.
     *
     * ─────────────────────────────────────────────────────────────────────
     * EL BORRADO EMPIEZA FUERA DE ESTA BASE DE DATOS, Y ESE ORDEN NO ES
     * OPCIONAL.
     * ─────────────────────────────────────────────────────────────────────
     * Las tarjetas viven en la bóveda de Cybersource y aquí solo hay ids de
     * token. Si se borrara la cuenta primero, la foreign key se llevaría las
     * filas con los ids dentro y quedarían tokens VIVOS que nadie sabe que
     * existen: tarjetas cobrables de alguien que ya no tiene cuenta. Por eso
     * la bóveda va delante y por eso, si no contesta, esto revienta y la
     * cuenta NO se borra — el asistente vuelve a intentarlo, que es mucho
     * mejor que quedarse sin cuenta y con la tarjeta viva.
     */
    public function borrar(Request $request, OlvidarTarjetasDeLaCuenta $olvidarTarjetas): Response
    {
        $cuenta = $this->cuenta($request);

        $olvidarTarjetas($cuenta);

        // El código de entrada vigente de ese buzón muere CON la cuenta: si
        // sobreviviera, canjearlo un minuto después la resucitaría — y el
        // borrado que exige Apple no puede tener un deshacer que nadie
        // pidió.
        EventAppLoginCode::query()->where('email', $cuenta->email)->delete();

        // Explícito aunque la foreign key ya arrastre las sesiones: que el
        // borrado se lea completo aquí y no dependa de recordar un cascade
        // que vive en una migración.
        $cuenta->sessions()->delete();
        $cuenta->delete();

        return response()->noContent();
    }

    /**
     * El 429 de esta puerta: `code` para que la app distinga el porqué y
     * `Retry-After` EN SEGUNDOS para que sepa cuánto callar antes de
     * volver — la app ya lee la cabecera. El `message` no es contrato;
     * solo el `code` lo es.
     */
    private function demasiados(string $code, string $message, int $reintentaEn): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
        ], 429, ['Retry-After' => (string) $reintentaEn]);
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

    /**
     * La forma del contrato: id, nombre, email — en español hacia fuera,
     * como todo lo que ve la app.
     *
     * @return array{id: int, nombre: string|null, email: string}
     */
    private function cuentaPublicada(EventAppAccount $cuenta): array
    {
        return [
            'id' => $cuenta->id,
            'nombre' => $cuenta->name,
            'email' => $cuenta->email,
        ];
    }
}
