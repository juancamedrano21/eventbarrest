<?php

declare(strict_types=1);

namespace App\Domains\Payments\Exceptions;

use App\Domains\Payments\Services\MensajeDeCybersource;
use RuntimeException;
use Throwable;

/**
 * Errores operables del dominio de pagos. Mismo criterio que SalesException:
 * cada uno lleva un código estable machine-readable, porque quien clasifica
 * al otro lado —la app del asistente, un reintento automático— no puede
 * depender de una frase en español que mañana se reescribe.
 *
 * La distinción que importa aquí no es «error» vs «no error», sino
 * **¿se movió dinero?**. Los constructores están agrupados por esa pregunta.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EL MENSAJE SE REDACTA EN EL CONSTRUCTOR, NO EN CADA FÁBRICA.
 * ─────────────────────────────────────────────────────────────────────────
 * Varias de estas fábricas interpolan el mensaje de una `ApiException` del
 * SDK, y ese mensaje lleva DENTRO la URL del TMS con el `customerTokenId` y
 * el `paymentInstrumentId` enteros — o sea, la credencial con la que se cobra
 * (ver `MensajeDeCybersource`). Como de aquí el texto va a un `Log::error` y,
 * con `APP_DEBUG=true`, al cuerpo del 500 que recibe el teléfono, la
 * redacción tiene que ser imposible de olvidar: se aplica al mensaje entero,
 * una sola vez, en el sitio por el que pasan TODAS — incluida la fábrica que
 * alguien añada mañana sin haber leído esto.
 */
class PaymentsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'payments_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct(MensajeDeCybersource::redactado($message), 0, $previous);
    }

    // ── Configuración: revientan antes de tocar la red ──────────────────

    /**
     * El seguro que impide cobrar de verdad desde una máquina que no es
     * producción. Ver EntornoDePortalDom para el porqué largo.
     */
    public static function entornoLiveFueraDeProduccion(string $appEnv): self
    {
        return new self(
            "PORTALDOM_ENV=live con APP_ENV={$appEnv}: un cobro real desde una máquina que no es producción. "
            .'Pon PORTALDOM_ENV=test o arranca con APP_ENV=production.',
            'portaldom_live_fuera_de_produccion',
        );
    }

    /**
     * La etiqueta del entorno dice pruebas, pero el host al que se conecta el
     * socket es de producción. `PORTALDOM_API_HOST` es la variable que decide
     * a dónde va el dinero, así que gana ella.
     */
    public static function hostDeProduccionFueraDeProduccion(string $appEnv, string $apiHost): self
    {
        return new self(
            "PORTALDOM_API_HOST={$apiHost} con APP_ENV={$appEnv}: ese host cobra de verdad, "
            .'y esta máquina no es producción. Pon PORTALDOM_API_HOST=apitest.cybersource.com.',
            'portaldom_host_de_produccion_fuera_de_produccion',
        );
    }

    /**
     * `PORTALDOM_ENV` y `PORTALDOM_API_HOST` dicen cosas distintas. Da igual
     * cuál de las dos combinaciones sea: una miente, y cuál miente no se sabe
     * hasta que aparece el cobro que sobra o el que falta.
     */
    public static function entornoYHostSeContradicen(string $portaldomEnv, string $apiHost): self
    {
        return new self(
            "PORTALDOM_ENV={$portaldomEnv} y PORTALDOM_API_HOST={$apiHost} se contradicen: "
            .'`test` va con apitest.cybersource.com y `live` con un host de producción.',
            'portaldom_entorno_y_host_se_contradicen',
        );
    }

    public static function faltanCredenciales(): self
    {
        return new self(
            'Faltan credenciales de PortalDOM: PORTALDOM_ORG_ID, PORTALDOM_KEY_ID y PORTALDOM_SHARED_SECRET.',
            'portaldom_sin_credenciales',
        );
    }

    public static function panSoloEnSandbox(string $host): self
    {
        return new self(
            "El cobro con PAN en claro solo existe para probar contra el sandbox, y el host es {$host}. "
            .'En producción la tarjeta se captura fuera de este servidor (SAQ A): PAN aquí es alcance SAQ D.',
            'pan_solo_en_sandbox',
        );
    }

    public static function importeInvalido(int $cents): self
    {
        return new self(
            "Importe inválido: {$cents} centavos. Un cobro es siempre un entero positivo de centavos.",
            'importe_invalido',
        );
    }

    public static function referenciaVacia(): self
    {
        return new self(
            'Un cobro sin referencia no se puede conciliar: clientReferenceInformation.code es obligatorio.',
            'referencia_vacia',
        );
    }

    /**
     * La query de `/tss/v2/searches` es un índice de texto: una referencia con
     * comillas o contrabarras no falla, cambia la consulta — y entonces la
     * conciliación contesta por otra transacción, que es peor que no contestar.
     */
    public static function referenciaNoConsultable(string $referencia): self
    {
        return new self(
            "La referencia `{$referencia}` lleva comillas o contrabarras y no se puede consultar en "
            .'/tss/v2/searches sin alterar la query. Las referencias de la casa son alfanuméricas.',
            'referencia_no_consultable',
        );
    }

    /**
     * Cybersource acota la llave a 1-64 caracteres. Mandar una más larga no
     * da error de validación: da una llave truncada, o sea idempotencia que
     * no idempotiza.
     */
    public static function idempotencyKeyInvalida(int $longitud): self
    {
        return new self(
            "Idempotency key inválida: longitud {$longitud}, y Cybersource acepta de 1 a 64 caracteres.",
            'idempotency_key_invalida',
        );
    }

    /**
     * Una credencial en blanco no es «sin dato»: sale por el cable como
     * cadena vacía y Cybersource rechaza el campo en vez de ignorarlo
     * (lección 7 de §0.2). El objeto de valor tiene la información para
     * evitarlo antes de gastar una ida a la red.
     */
    public static function credencialVacia(string $campo): self
    {
        return new self(
            "La credencial `{$campo}` viene vacía: Cybersource rechaza el campo en blanco en vez de ignorarlo. "
            .'Un cobro sin credencial no se manda.',
            'credencial_vacia',
        );
    }

    // ── Transporte: NO se sabe si se movió dinero ───────────────────────

    /**
     * Ni respuesta ni rechazo: la llamada no llegó a completarse. Es el único
     * desenlace en el que NO se puede afirmar que no se cobró — puede que
     * Cybersource autorizara y se perdiera la respuesta.
     *
     * La salida segura es reintentar con la MISMA `v-c-idempotency-id`: dentro
     * de la ventana de 15 minutos Cybersource devuelve la respuesta cacheada
     * en vez de volver a cobrar. Un reintento con llave nueva es un cobro nuevo.
     */
    public static function falloDeTransporte(Throwable $causa): self
    {
        return new self(
            'La llamada a Cybersource no se completó: '.$causa->getMessage()
            .'. Reintenta con la MISMA idempotency key — con una nueva se cobra otra vez.',
            'fallo_de_transporte',
            $causa,
        );
    }

    /**
     * La búsqueda de conciliación no se pudo hacer.
     *
     * Se falla ruidoso en vez de devolver «no encontré nada» porque esas dos
     * respuestas llevan a decisiones opuestas: «no existe» autoriza a
     * reintentar, «no pude mirar» prohíbe hacerlo. Confundirlas es el doble
     * cobro que la conciliación venía a evitar.
     *
     * Si aparece un 401/403 aquí, lo más probable es que el MID no tenga
     * habilitada la búsqueda de transacciones: eso se le pide a PortalDOM.
     */
    public static function busquedaNoDisponible(string $referencia, int $httpStatus, string $detalle): self
    {
        return new self(
            "[{$referencia}] no se pudo consultar /tss/v2/searches (HTTP {$httpStatus}): {$detalle}. "
            .'NO reintentes el cobro: sin esta respuesta no se sabe si la referencia ya existe. '
            .'Si el MID no tiene habilitada la búsqueda de transacciones, hay que pedírsela a PortalDOM.',
            'busqueda_no_disponible',
        );
    }

    /**
     * La bóveda (TMS) no contestó lo que se esperaba.
     *
     * Se falla ruidoso por el mismo motivo que la búsqueda: quien llama tiene
     * que poder distinguir «la tarjeta ya no está allí» —que es un 404 o un
     * 410 y se trata como éxito— de «no pude hablar con la bóveda». Confundir
     * el segundo con el primero es borrar la fila local dejando el token
     * VIVO: una tarjeta que el asistente cree haber quitado y que se sigue
     * pudiendo cobrar.
     *
     * `$operacion` es la ruta del TMS con marcadores, no el id: los
     * identificadores de la bóveda son credenciales de cobro y no se escriben
     * enteros en ningún sitio (garantía transversal del cobro con tarjeta).
     * `$detalle` viene del SDK y por tanto trae la URL real con los dos
     * tokens dentro — de eso se encarga la aduana del constructor, que es
     * quien sostiene la garantía y no la buena memoria de quien llama.
     */
    public static function bovedaNoDisponible(string $operacion, int $httpStatus, string $detalle): self
    {
        return new self(
            "No se pudo completar `{$operacion}` en la bóveda de Cybersource (HTTP {$httpStatus}): {$detalle}. "
            .'NO borres la fila local: sin esta respuesta no se sabe si el token sigue vivo, y una fila '
            .'borrada con el token vivo es una tarjeta que se puede seguir cobrando.',
            'boveda_no_disponible',
        );
    }

    /**
     * La anulación del cobro de verificación no se pudo hacer.
     *
     * NO es un fallo del alta: la tarjeta quedó guardada y utilizable, que es
     * lo que el asistente pidió. Lo que queda pendiente es devolverle el
     * importe simbólico de la verificación, y eso NO se resuelve mirando el
     * log: el rastro durable es la fila, que guarda la referencia y el id de
     * transacción y se queda con `verification_voided_at` en null hasta que
     * alguien la reconcilie (`EventAppCard::pendientesDeAnular()`). La
     * excepción los lleva igual porque el log sigue siendo el sitio donde se
     * ve QUÉ falló.
     */
    public static function anulacionNoRealizada(string $referencia, ?string $transactionId, string $detalle): self
    {
        return new self(
            "[{$referencia}] no se pudo anular el cobro de verificación (txn {$transactionId}): {$detalle}. "
            .'La tarjeta SÍ quedó guardada; lo que falta es devolver el importe de la verificación.',
            'anulacion_no_realizada',
        );
    }

    // ── El peor estado posible: cobrado sin credencial ──────────────────

    /**
     * Se pidió TOKEN_CREATE, el cobro aprobó y no volvió token. El dinero se
     * movió y la tarjeta NO quedó guardada: el asistente pagó y la próxima
     * compra le vuelve a pedir la tarjeta, o peor, alguien asume que hay
     * credencial y construye encima. Se falla ruidoso a propósito.
     */
    public static function aprobadoSinToken(string $referencia, ?string $transactionId): self
    {
        return new self(
            "[{$referencia}] el cobro se aprobó (txn {$transactionId}) pero Cybersource no devolvió token: "
            .'la tarjeta NO quedó guardada. Revisa el cobro a mano antes de reintentar.',
            'aprobado_sin_token',
        );
    }

    /**
     * Aprobado sin `processorInformation.networkTransactionId`. Ese es el ancla
     * del encadenado de credencial en archivo (NO el id de la transacción):
     * sin él, los cobros siguientes con la tarjeta guardada no se pueden
     * encadenar y la red los puede rechazar o recategorizar.
     */
    public static function aprobadoSinAnclaDeRed(string $referencia, ?string $transactionId): self
    {
        return new self(
            "[{$referencia}] el cobro se aprobó (txn {$transactionId}) pero falta networkTransactionId: "
            .'los cobros siguientes con esta tarjeta no podrían encadenar.',
            'aprobado_sin_ancla_de_red',
        );
    }
}
