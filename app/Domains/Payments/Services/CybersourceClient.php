<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\EntornoDePortalDom;
use App\Domains\Payments\Exceptions\PaymentsException;
use CyberSource\ApiClient;
use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Configuration;
use CyberSource\ObjectSerializer;

/**
 * Arma el SDK de Cybersource a partir de `config('services.portaldom')`.
 *
 * Es el único sitio del proyecto donde se leen las credenciales y donde se
 * construyen los objetos del SDK. Se registra como singleton para que la
 * configuración y su validación se hagan una vez por proceso.
 *
 * Lo que hay aquí no es envoltorio: es la lista de trampas del SDK que en
 * Boletu se pagaron una por una en producción. Cada una está comentada con lo
 * que se rompía, porque todas parecen código sobrante hasta que se quitan.
 */
final class CybersourceClient
{
    private ?MerchantConfiguration $merchantConfig = null;

    private ?ApiClient $apiClient = null;

    public function __construct()
    {
        // Segunda puerta del seguro de entorno. La primera está en
        // config/services.php, pero con `php artisan config:cache` ese
        // fichero no se ejecuta nunca: la configuración se lee de un array
        // ya serializado. Sin esta comprobación, un despliegue con la
        // configuración cacheada podría cobrar en vivo desde una máquina que
        // no es producción sin que la primera puerta llegara a abrirse.
        EntornoDePortalDom::comprobar(
            (string) config('app.env', 'local'),
            (string) config('services.portaldom.env', EntornoDePortalDom::TEST),
            $this->host(),
        );
    }

    /**
     * ¿Están las tres credenciales puestas?
     *
     * Existe para que las pruebas contra el sandbox se salten solas: una
     * suite que exige credenciales de un integrador de pagos es una suite que
     * nadie más puede correr, ni CI ni un clon recién hecho.
     */
    public static function hayCredenciales(): bool
    {
        foreach (['org_id', 'key_id', 'shared_secret'] as $clave) {
            if (trim((string) config("services.portaldom.{$clave}")) === '') {
                return false;
            }
        }

        return true;
    }

    /** El host contra el que se firma Y se conecta. */
    public function host(): string
    {
        $host = trim((string) config('services.portaldom.api_host'));

        return $host !== '' ? $host : EntornoDePortalDom::hostPorDefecto(
            (string) config('services.portaldom.env', EntornoDePortalDom::TEST)
        );
    }

    /**
     * Se responde con el HOST, no con `PORTALDOM_ENV`.
     *
     * La etiqueta del entorno es una intención; el host es a dónde va el
     * dinero (`ApiClient` arma la URL con `Configuration::getHost()`). Quien
     * pregunta «¿esto es sandbox?» —el modo PAN, sobre todo— está preguntando
     * lo segundo. El constructor ya garantiza que las dos no se contradicen,
     * así que aquí no hay dos verdades: hay una, y es la que decide.
     */
    public function esSandbox(): bool
    {
        return EntornoDePortalDom::esHostDeSandbox($this->host());
    }

    /**
     * La configuración de comercio: quién firma y con qué.
     *
     * Tres detalles que no son estilo:
     *
     * 1. `HTTP_SIGNATURE` va en MAYÚSCULAS. El SDK compara la cadena literal
     *    contra `GlobalParameter::HTTP_SIGNATURE`; en minúsculas no falla al
     *    configurar, simplemente no firma como esperas.
     * 2. El `shared_secret` se entrega TAL CUAL viene de PortalDOM, en
     *    base64. El SDK lo decodifica antes de usarlo como llave del HMAC
     *    (`base64_decode`). Decodificarlo aquí «para ayudar» produce una
     *    firma matemáticamente correcta que Cybersource rechaza.
     * 3. `validateMerchantData()` al final: convierte una credencial ausente
     *    en una excepción aquí y ahora, en vez de un 401 opaco en la primera
     *    llamada de verdad, que es donde más caro sale entenderlo.
     *
     * MLE, mTLS y JWT se apagan explícitamente aunque sus defectos ya sean los
     * correctos: son los interruptores que, encendidos por accidente, dan
     * errores que no se parecen en nada a su causa. El proxy NO se apaga aquí
     * porque no se lee de aquí — ver `configuracionDelSdk()`.
     */
    public function merchantConfig(): MerchantConfiguration
    {
        if ($this->merchantConfig !== null) {
            return $this->merchantConfig;
        }

        if (! self::hayCredenciales()) {
            throw PaymentsException::faltanCredenciales();
        }

        return $this->merchantConfig = $this->sinDeprecations(function (): MerchantConfiguration {
            $config = new MerchantConfiguration;

            $config->setAuthenticationType('HTTP_SIGNATURE');
            $config->setMerchantID((string) config('services.portaldom.org_id'));
            $config->setApiKeyID((string) config('services.portaldom.key_id'));
            $config->setSecretKey((string) config('services.portaldom.shared_secret'));
            $config->setRunEnvironment($this->host());
            $config->setIntermediateHost('');

            // Nada de cifrado de mensaje ni certificado de cliente.
            $config->setUseMLEGlobally(false);
            $config->setEnableResponseMleGlobally(false);
            $config->setEnableClientCert(false);

            // El SDK tiene su PROPIO log, a su propio fichero, y ahí escribe
            // el cuerpo entero de la respuesta —token de cliente y de
            // instrumento incluidos— fuera del alcance de `redactado()` y de
            // `paraLog()`. Viene apagado de fábrica (`LogConfiguration::
            // $enableLogging = false`), pero se apaga aquí EXPLÍCITAMENTE:
            // encenderlo para depurar es un cambio de una línea, y con él la
            // credencial de cobro sale a un fichero que nadie está mirando.
            // El enmascarado queda encendido por si alguien lo enciende igual.
            $log = $config->getLogConfiguration();
            $log->enableLogging(false);
            $log->enableMasking(true);
            $config->setLogConfiguration($log);

            $config->validateMerchantData();

            return $config;
        });
    }

    /**
     * ────────────────────────────────────────────────────────────────────
     * AQUÍ ESTÁ EL FALLO QUE MÁS CARO SALIÓ EN BOLETU. NO LO «LIMPIES».
     * ────────────────────────────────────────────────────────────────────
     *
     * El SDK tiene DOS objetos de configuración y los usa para cosas
     * distintas:
     *
     * - `MerchantConfiguration::getRunEnvironment()` → con esto se FIRMA
     *   (entra en la cadena canónica como cabecera `host`).
     * - `Configuration::getHost()` → con esto `ApiClient::callApi()` arma la
     *   URL, o sea a dónde se CONECTA de verdad el socket.
     *
     * Y `Configuration::$host` tiene por defecto `apitest.cybersource.com`
     * (v0.0.75, `lib/Configuration.php:101`). Así que si solo se fija el
     * primero, que es lo que parece bastar leyendo el SDK por encima:
     *
     *   - en test el defecto coincide con la intención → todo pasa, y el
     *     fallo queda invisible en cualquier prueba;
     *   - en producción se firma con `Host: api.cybersource.com` y se conecta
     *     a `apitest.cybersource.com`, que no conoce esas credenciales →
     *     **401 que solo aparece en producción**, con la firma «correcta» y
     *     sin nada en el código que lo insinúe.
     *
     * Por eso los dos objetos salen SIEMPRE de aquí, con el mismo host, y no
     * hay ningún `new Configuration` suelto en la clase: mientras esto sea el
     * único sitio que fabrica `Configuration`, el fallo no se puede
     * reintroducir olvidándolo en una de las dos rutas.
     *
     * Y de paso el proxy: `ApiClient` lo lee de ESTE objeto
     * (`ApiClient.php:344`), no del de comercio. Apagarlo en el otro es un
     * apagado que no apaga nada — la misma trampa de los dos objetos, en
     * pequeño.
     */
    private function configuracionDelSdk(): Configuration
    {
        $sdkConfig = new Configuration;
        $sdkConfig->setHost($this->merchantConfig()->getRunEnvironment());
        $sdkConfig->setCurlProxyHost('');
        $sdkConfig->setCurlProxyPort(0);

        // Sin plazo, la llamada del dinero espera lo que quiera el otro lado:
        // el SDK trae `curlTimeout = 0`, que en curl significa «para siempre».
        // Un proceso PHP colgado dentro de POST /pts/v2/payments muere por el
        // límite de ejecución sin dejar resultado NI línea de error, que es el
        // peor final posible — se cobró o no y nadie lo sabe. Con plazo, el
        // corte lo damos nosotros y sale por el camino `incierto`, que es
        // ruidoso y tiene reconciliación.
        //
        // Los números salen de la misma config que el resto y son los de
        // Boletu en producción: 10 s para conectar, 30 s de transferencia.
        // Treinta es holgado a propósito: un 3DS con el emisor tarda, y
        // cortar antes de tiempo fabrica incertidumbre en vez de evitarla.
        $sdkConfig->setCurlConnectTimeout((int) config('services.portaldom.connect_timeout', 10));
        $sdkConfig->setCurlTimeout((int) config('services.portaldom.timeout', 30));

        return $sdkConfig;
    }

    /**
     * El cliente HTTP compartido, para las llamadas donde la idempotencia no
     * aplica (consultas, búsquedas).
     */
    public function apiClient(): ApiClient
    {
        if ($this->apiClient !== null) {
            return $this->apiClient;
        }

        return $this->apiClient = $this->sinDeprecations(
            fn (): ApiClient => new ApiClient($this->configuracionDelSdk(), $this->merchantConfig())
        );
    }

    /**
     * Un ApiClient EFÍMERO con la cabecera `v-c-idempotency-id`, para
     * `/pts/v2/payments`.
     *
     * NO se cachea, y eso es lo importante. La cabecera vive en el
     * `Configuration`, así que ponerla sobre el cliente compartido la dejaría
     * pegada a TODAS las llamadas siguientes del mismo proceso: la segunda
     * compra del asistente llegaría con la llave de la primera y Cybersource
     * devolvería la respuesta cacheada de aquella —un cobro que nunca
     * ocurrió— o un 400 de conflicto. Bajo Octane, donde el singleton
     * sobrevive entre peticiones HTTP, sería entre usuarios distintos.
     *
     * Un `Configuration` nuevo es un contenedor de banderas: no hace ni I/O
     * ni handshake, cuesta microsegundos.
     *
     * Qué hace Cybersource con la cabecera:
     *   - misma llave + mismo cuerpo dentro de 15 minutos → devuelve la
     *     respuesta cacheada SIN volver a cobrar. Ese es todo el punto.
     *   - misma llave + cuerpo distinto → 400 Idempotency-Conflict.
     *   - misma llave con la primera llamada todavía en vuelo → 409.
     *   - sin cabecera → sin idempotencia, y en un festival el doble cobro
     *     por mala señal no es teórico.
     */
    public function apiClientWithIdempotency(string $idempotencyKey): ApiClient
    {
        $longitud = mb_strlen($idempotencyKey);

        if ($longitud === 0 || $longitud > 64) {
            throw PaymentsException::idempotencyKeyInvalida($longitud);
        }

        return $this->sinDeprecations(function () use ($idempotencyKey): ApiClient {
            $sdkConfig = $this->configuracionDelSdk();
            $sdkConfig->addDefaultHeader('v-c-idempotency-id', $idempotencyKey);

            return new ApiClient($sdkConfig, $this->merchantConfig());
        });
    }

    /**
     * Convierte un array plano en el modelo tipado que exige el SDK.
     *
     * Los métodos generados del SDK no aceptan arrays: llaman getters sobre
     * clases concretas. En vez de instanciar a mano el grafo entero de
     * submodelos, lo hidrata el propio serializador del SDK. Así nuestro
     * código escribe arrays —legibles y comparables en un test— y el grafo
     * de clases generadas se queda donde debe: como detalle de la librería.
     *
     * OJO al efecto de borde: `deserialize()` solo copia las claves que el
     * modelo declara en `$swaggerTypes`. Un campo mal escrito no da error,
     * DESAPARECE del cuerpo. Por eso los tests de forma comparan el array que
     * construimos, y además hay uno que lo pasa por aquí y verifica que
     * sobrevive entero.
     *
     * @template T of object
     *
     * @param  array<string, mixed>  $datos
     * @param  class-string<T>  $clase
     * @return T
     */
    public function sdkModel(array $datos, string $clase): object
    {
        return $this->sinDeprecations(function () use ($datos, $clase): object {
            /** @var T $modelo */
            $modelo = ObjectSerializer::deserialize(json_decode((string) json_encode($datos)), $clase);

            return $modelo;
        });
    }

    /**
     * Corre el callback con las deprecations apagadas.
     *
     * El SDK es autogenerado y sigue escribiendo parámetros con nulabilidad
     * implícita (`Configuration $config = null`), que PHP 8.4 marca como
     * E_DEPRECATED. Son decenas por construcción y no son un problema de
     * corrección: apagarlas ACOTADO a la instanciación evita que tapen en el
     * log lo que sí importa. Fuera de este método las deprecations siguen
     * encendidas — es lo que hace que esto sea acotado y no un silenciador.
     *
     * @template TRetorno
     *
     * @param  callable(): TRetorno  $callback
     * @return TRetorno
     */
    private function sinDeprecations(callable $callback): mixed
    {
        $previo = error_reporting();
        error_reporting($previo & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            return $callback();
        } finally {
            error_reporting($previo);
        }
    }
}
