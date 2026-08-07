<?php

declare(strict_types=1);

use App\Domains\Payments\EntornoDePortalDom;

$portaldomEnv = (string) env('PORTALDOM_ENV', EntornoDePortalDom::TEST);

// El host se DERIVA del entorno cuando nadie lo fija. Dejar aquí el defecto
// de producción a secas (como hace Boletu) significa que un .env con
// PORTALDOM_ENV=test y sin API_HOST firma contra el sandbox y conecta a
// producción; derivarlo hace que las dos variables no puedan contradecirse
// por omisión.
$portaldomHost = (string) env('PORTALDOM_API_HOST', EntornoDePortalDom::hostPorDefecto($portaldomEnv));

// Nadie cobra de verdad desde una máquina de desarrollo por error. Con
// PORTALDOM_ENV=live —o con un PORTALDOM_API_HOST de producción, que es la
// variable que de verdad decide a dónde va el dinero— y APP_ENV distinto de
// production, la aplicación no arranca. El porqué largo está en
// EntornoDePortalDom; aquí basta con saber que este es el punto más temprano
// en que se puede negar el arranque, y que el cliente del SDK lo vuelve a
// comprobar porque con `config:cache` este fichero no se ejecuta.
EntornoDePortalDom::comprobar(
    (string) env('APP_ENV', 'local'),
    $portaldomEnv,
    $portaldomHost,
);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PortalDOM (Cybersource en República Dominicana)
    |--------------------------------------------------------------------------
    |
    | PortalDOM es el integrador local de Cybersource en RD. Las mismas claves
    | que ya usa Boletu en producción, para que las credenciales y el
    | conocimiento del Business Center se reaprovechen tal cual.
    |
    | Autenticación: HTTP Signature (HMAC-SHA256). Son tres valores y los tres
    | son secretos; el `shared_secret` viene en base64 y se guarda TAL CUAL
    | llega —el SDK lo decodifica antes de firmar—, así que recortarlo o
    | «limpiarlo» rompe la firma sin que nada lo diga hasta el 401.
    */
    'portaldom' => [
        'org_id' => env('PORTALDOM_ORG_ID'),        // v-c-merchant-id
        'key_id' => env('PORTALDOM_KEY_ID'),        // keyid dentro de la firma
        'shared_secret' => env('PORTALDOM_SHARED_SECRET'), // llave HMAC, en base64

        // 'test' | 'live'. Lo vigila EntornoDePortalDom (arriba).
        'env' => $portaldomEnv,

        // Derivado del entorno cuando nadie lo fija, y comprobado arriba
        // contra él: es el que decide a dónde va el dinero.
        'api_host' => $portaldomHost,

        'locale' => env('PORTALDOM_LOCALE', 'es_ES'),
        'country' => env('PORTALDOM_COUNTRY', 'DO'),
        'currency' => env('PORTALDOM_CURRENCY', 'DOP'),

        // Merchant Defined Data que exige Visanet RD. La clave 2 es el
        // `org_id` de arriba y no necesita variable propia. La clave 3 es el
        // canal: aquí es la app del asistente, no la web de boletería.
        'merchant_category' => env('PORTALDOM_MERCHANT_CATEGORY', 'FOOD'),
        'channel' => env('PORTALDOM_CHANNEL', 'APP'),

        // Plazos de la llamada del dinero, en segundos. El SDK trae cero, que
        // en curl es «espera para siempre»: un proceso colgado dentro del
        // cobro muere por el límite de ejecución sin dejar resultado ni línea
        // de error, y ahí nadie sabe si se cobró. Con plazo, el corte lo damos
        // nosotros y sale por el camino `incierto`, que sí es ruidoso.
        // Treinta segundos de transferencia es holgado a propósito: el 3DS
        // con el emisor tarda, y cortar antes de tiempo fabrica la
        // incertidumbre que se quiere evitar.
        'connect_timeout' => (int) env('PORTALDOM_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('PORTALDOM_TIMEOUT', 30),
    ],

];
