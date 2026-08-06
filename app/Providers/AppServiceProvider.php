<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\EventApp\Correo\TransporteDeCodigos;
use App\Domains\EventApp\Correo\TransporteDeCodigosAlLog;
use App\Domains\EventApp\Correo\TransporteDeCodigosSinProveedor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
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
        // Cómo viaja el código de entrada de la app del asistente. Hoy no
        // hay proveedor de correo: en local y testing el código va al log;
        // en PRODUCCIÓN el binding falla ruidoso al enviar, con un mensaje
        // que dice qué configurar. La puerta de entorno vive AQUÍ y no en
        // un comentario: sin ella, desplegar tal cual escribía cada OTP de
        // cada asistente EN CLARO en storage/logs — que ven el despliegue,
        // los respaldos y cualquier agregador. El día que llegue el
        // proveedor real, su implementación sustituye a la rama de
        // producción y nada más — el código que decide (emitir, canjear,
        // frenar) no conoce el transporte. El entorno se mira AL RESOLVER,
        // no al registrar, para que la decisión no se congele antes de que
        // el entorno esté fijado (y para poder probarla).
        $this->app->bind(
            TransporteDeCodigos::class,
            fn (Application $app): TransporteDeCodigos => $app->environment('production')
                ? $app->make(TransporteDeCodigosSinProveedor::class)
                : $app->make(TransporteDeCodigosAlLog::class),
        );
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

        // LA PUERTA DE LA APP DEL ASISTENTE NO TIENE FRENO, Y ESO ES UNA
        // DECISIÓN MEDIDA CONTRA LA REGLA DE LA CASA, NO UN OLVIDO.
        //
        // Tuvo uno: 600 por minuto por (evento, IP). El número no era el
        // problema; la llave sí, y no hay número que la arregle. Mientras
        // `trustProxies(at: '*')` siga abierto, la IP la ESCRIBE quien llama,
        // y de ahí salen las dos mitades del mismo fallo:
        //
        // - Contra quien ataca no cuenta: estrena IP —y con ella cubo— en
        //   cada petición, así que jamás llega al techo.
        // - Contra el público sí: los teléfonos de un festival salen por el
        //   NAT de dos o tres operadores, así que miles de asistentes honestos
        //   comparten UN cubo. Doc 11 §6 habla de +6.000 personas; a dos
        //   peticiones por arranque, la cola del sábado a las nueve llena 600
        //   en un minuto y el siguiente que abre la app recibe un 429.
        //
        // Y hay una tercera, que es la que lo cierra: quien ataca elige QUÉ
        // cubo llena. Basta con mandar 600 peticiones poniendo en
        // X-Forwarded-For la IP del operador para dejar sin app, evento a
        // evento, a todo el que salga por ella. Eso es literalmente el botón
        // de apagado con otro nombre del que habla CLAUDE.md: un contador que
        // sube quien ataca, sobre algo que él elige. Un freno que no puede
        // acertar y sí puede negar un acierto vale menos que ninguno.
        //
        // Lo que hace baratos estos endpoints no era el freno: son de SOLO
        // LECTURA y llevan ETag, así que la app que repite recibe 304s sin
        // cuerpo, y no hay nada que escribir detrás que un exceso pueda
        // corromper.
        //
        // El techo de volumen que sí hace falta va en el BORDE (ngrok hoy,
        // el balanceador mañana), que es el único sitio donde la IP todavía
        // es cierta. Doc 11 lo llama requisito de esta puerta y no opcional.
        // El día que `at:` se acote a los rangos del borde, `$request->ip()`
        // vuelve a discriminar y este freno se puede reescribir aquí con un
        // número defendible; hasta entonces, ninguno lo es.
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
