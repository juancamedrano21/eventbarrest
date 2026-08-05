<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\EventApp\Actions\IssueEventPublicCode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El tema Preline Pro (licenciado) vive fuera de git: si está
        // restaurado, las pantallas del panel usan su layout; si no, el
        // layout simple — así los tests y un clon fresco funcionan igual.
        if (is_dir(resource_path('panel-theme/views'))) {
            View::addNamespace('paneltheme', resource_path('panel-theme/views'));
            View::share('panelLayout', 'paneltheme::layout');
        } else {
            View::share('panelLayout', 'event-panel.layout');
        }

        // El login del POS: cinco por minuto y usuario+origen.
        //
        // La llave se compone con `username` —decía `email`, que nunca llega,
        // así que la llave efectiva era «|IP» y en un festival, donde todas
        // las cajas salen por el mismo NAT, la sexta tablet recibía 429 sin
        // que nadie fallara—. Y se compone con el usuario NORMALIZADO igual
        // que lo normaliza PosAuthController al buscarlo, `mb_strtolower(trim())`.
        // Con el valor crudo, 'caro' y 'CaRo' eran dos cubos para la MISMA
        // cuenta: medido, 60 adivinanzas por minuto contra una cajera en vez
        // de cinco, porque las 2^n variantes de mayúsculas estrenaban cubo y
        // todas autenticaban igual. Es el mismo porqué que ya explicaba
        // KdsEnrollController::llaveDelFreno para el código del comercio.
        //
        // NO HAY AQUÍ UN SEGUNDO LÍMITE POR ORIGEN A SECAS, y es deliberado.
        // Un techo por IP sobre esta ruta es o esquivable (la IP la escribe
        // quien llama, ver bootstrap/app.php) o, si se dejara de creer la
        // cabecera, un cubo único que apagaría el login de TODAS las cajas de
        // TODOS los comercios con sesenta peticiones por minuto. El gasto que
        // ese techo venía a acotar se ha quitado en la fuente: PosAuthController
        // hace ahora UN solo bcrypt por petición, exista el usuario o no.
        //
        // Y este es el ÚNICO freno de esa puerta a propósito. El contador de
        // fallos por cuenta que hubo en la base se quitó porque enumeraba
        // usuarios por código de estado sin capar una sola adivinanza; el
        // porqué medido está en PosAuthController.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(5)
            ->by(self::usuarioNormalizado($request->input('username')).'|'.$request->ip())
            ->response(self::demasiadosDelPos(...)));

        // El alta de una tablet: sesenta por minuto y código+origen.
        //
        // Antes eran DIEZ, y ese número tumbaba el montaje: este limitador es
        // el de Laravel y cuenta TODAS las peticiones, aciertos incluidos, y
        // las veinte tabletas de una cocina comparten el código impreso y el
        // router del recinto. La undécima recibía 429 sin que nadie se hubiera
        // equivocado, la mañana en que menos se puede parar a esperar. Sesenta
        // deja el triple de margen a ese montaje y sigue siendo un techo.
        //
        // El código se normaliza igual que en el controlador y en la acción
        // —sin guiones y en mayúscula— para que «abcd-1234» y «ABCD1234» sean
        // el mismo cubo, y `soloTexto` lo protege de un `codigo[]` que aquí,
        // ANTES de validar, contestaría 500 en vez de 422.
        //
        // Se descartó quitarle el código a la llave y dejar solo el origen:
        // con la IP colapsada eso es un cubo único de plataforma que cuenta
        // aciertos, o sea el mismo 429 del montaje movido de «por cocina» a
        // «por festival entero». Y el gasto de CPU que ese cubo pretendía
        // acotar se acota donde nace: EnrollKdsDevice ya no abre ningún
        // abanico —el índice ciego del PIN deja una petición en UN bcrypt,
        // tenga el comercio una barra o treinta—, así que este techo solo
        // tiene que ser un techo de volumen y no un racionamiento de CPU.
        RateLimiter::for('kds-enrolar', fn (Request $request) => Limit::perMinute(60)
            ->by(self::codigoNormalizado($request->input('codigo')).'|'.$request->ip())
            ->response(self::demasiadosDelAlta(...)));

        // La puerta pública de la app del asistente: 600 por minuto, evento
        // y origen.
        //
        // EL NÚMERO ES GRANDE A PROPÓSITO Y ESO NO ES DEJADEZ. Aquí quien
        // llama no es una caja ni una tablet: son los teléfonos del público,
        // miles, y salen todos por el NAT de dos o tres operadores móviles.
        // Con la IP colapsada así, un techo estrecho no frena a quien ataca
        // —la IP la escribe quien llama, ver bootstrap/app.php— y sí deja
        // fuera al asistente que abrió la app en el peor momento del sábado.
        // Un freno que puede negar un acierto está mal, y esta puerta es
        // justo donde más caro sale.
        //
        // Lo que hace baratos estos endpoints no es el freno: es que son de
        // SOLO LECTURA y llevan ETag, así que la app repite y recibe 304s. El
        // limitador es un techo de volumen contra un cliente roto en bucle,
        // no la defensa — y decirlo así evita que alguien lo baje creyendo
        // que protege algo.
        //
        // El evento entra en la llave para que el bucle de la app de un
        // festival no consuma el cubo de otro que comparte operador. Se
        // normaliza con la MISMA pieza que usa la puerta al resolverlo: si
        // la llave y la consulta normalizaran distinto, «bocao-26» y
        // «BOCAO26» serían dos cubos del mismo evento y el freno no frenaría
        // —es el fallo que ya se pagó en pos-login con las mayúsculas—.
        RateLimiter::for('event-app', fn (Request $request) => Limit::perMinute(600)
            ->by(IssueEventPublicCode::normalizar($request->route('codigo')).'|'.$request->ip())
            ->response(self::demasiadasDeLaApp(...)));
    }

    /**
     * @param  array<string, mixed>  $cabeceras
     */
    private static function demasiadosDelPos(Request $request, array $cabeceras): JsonResponse
    {
        return self::demasiados('pos_demasiados_intentos', $cabeceras);
    }

    /**
     * @param  array<string, mixed>  $cabeceras
     */
    private static function demasiadosDelAlta(Request $request, array $cabeceras): JsonResponse
    {
        return self::demasiados('kds_demasiados_intentos', $cabeceras);
    }

    /**
     * Aquí no son «intentos»: nadie está adivinando nada, solo leyendo un
     * catálogo. El código lo dice para que la app no enseñe al asistente un
     * mensaje de credenciales que no ha tecleado.
     *
     * @param  array<string, mixed>  $cabeceras
     */
    private static function demasiadasDeLaApp(Request $request, array $cabeceras): JsonResponse
    {
        return response()->json([
            'code' => 'event_app_demasiadas_peticiones',
            'message' => 'Demasiadas peticiones. Espera un minuto y vuelve a probar.',
        ], 429, $cabeceras);
    }

    /**
     * El 429 del limitador, en el mismo formato que el resto de la API.
     *
     * Laravel contesta «Too Many Attempts.» en inglés y sin `code`, y el KDS
     * solo enseña `data.message` al cocinero: sin esto, la tablet de una
     * cocina dominicana muestra una frase en inglés que nadie sabe qué hacer
     * con ella. Las cabeceras del limitador —Retry-After y las X-RateLimit—
     * se conservan tal cual: son las que un cliente honesto usa para dejar de
     * insistir sin tener que leer el cuerpo.
     *
     * @param  array<string, mixed>  $cabeceras
     */
    private static function demasiados(string $code, array $cabeceras): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => 'Demasiados intentos. Espera un minuto y vuelve a probar.',
        ], 429, $cabeceras);
    }

    /**
     * El usuario tal como lo va a buscar el controlador.
     *
     * Si la llave del freno y la consulta no normalizan igual, el freno no
     * frena: basta con cambiar una mayúscula para estrenar cubo contra la
     * misma cuenta.
     */
    private static function usuarioNormalizado(mixed $valor): string
    {
        return mb_strtolower(trim(self::soloTexto($valor)));
    }

    /** El código tal como lo normalizan EnrollKdsDevice y el controlador. */
    private static function codigoNormalizado(mixed $valor): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', self::soloTexto($valor)));
    }

    /**
     * El valor tal cual, pero solo si es texto.
     *
     * `input()` devuelve lo que venga en el cuerpo, y el cuerpo lo escribe
     * quien llama: `username[]=a&username[]=b` mete aquí un array, el cast a
     * string revienta y el limitador —que corre en el middleware, ANTES de
     * validar— contesta un 500 en vez del 422 que tocaba. Un cuerpo raro no
     * puede ser la forma de tumbar el freno que protege la puerta.
     */
    private static function soloTexto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }
}
