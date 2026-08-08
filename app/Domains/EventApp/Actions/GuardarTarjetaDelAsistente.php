<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Actions;

use App\Domains\EventApp\AltaDeTarjeta;
use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppCard;
use App\Domains\Payments\Actions\AnularCobro;
use App\Domains\Payments\Actions\BuscarTarjetaEnLaBoveda;
use App\Domains\Payments\Actions\CobrarConTarjeta;
use App\Domains\Payments\CobroSolicitado;
use App\Domains\Payments\Enums\EstadoDeCobro;
use App\Domains\Payments\Enums\MarcaDeTarjeta;
use App\Domains\Payments\Exceptions\PaymentsException;
use App\Domains\Payments\ResultadoDeCobro;
use App\Domains\Payments\TarjetaEnLaBoveda;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guarda una tarjeta del asistente: la tokeniza en Cybersource y deja aquí lo
 * justo para pintarla.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * GUARDAR UNA TARJETA OBLIGA A COBRAR ALGO. NO ES UN CAPRICHO DEL DISEÑO.
 * ─────────────────────────────────────────────────────────────────────────
 * Cybersource tokeniza DENTRO de una autorización (`actionList:
 * ['TOKEN_CREATE']` sobre `/pts/v2/payments`): no hay «solo guardar». En este
 * slice ese cobro es de importe simbólico y se anula en el acto; el alta con
 * compra real —el caso natural, que es guardar la tarjeta al pagar el primer
 * pedido— llega con el pedido móvil y usará el mismo camino con el importe
 * de verdad y sin anulación.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * HOY CADA TARJETA ESTRENA SU PROPIO `customer` EN LA BÓVEDA. MEDIDO.
 * ─────────────────────────────────────────────────────────────────────────
 * La doc 12 §2 dibuja un customer por asistente con N tarjetas dentro, y así
 * será. Pero adjuntar una tarjeta nueva a un customer ya existente mandando
 * `paymentInformation.customer.id` junto con los datos de tarjeta devuelve
 * **400 INVALID_REQUEST / INVALID_DATA** — probado contra apitest el
 * 2026-08-07 con una Mastercard sobre el customer de una Visa recién
 * guardada. La forma que sí funciona hay que descubrirla con la captura real
 * de Unified Checkout (cuarto slice), que es de donde vendrá el
 * `transientTokenJwt`, y no antes.
 *
 * Consecuencia que el resto del código YA respeta: el `customer_token_id`
 * vive en CADA fila, no en la cuenta, y borrar la cuenta recorre los clientes
 * DISTINTOS que aparezcan. El día que un asistente tenga un solo customer,
 * nada de esto cambia.
 */
class GuardarTarjetaDelAsistente
{
    /**
     * El importe del cobro de verificación, en centavos.
     *
     * Un peso: lo bastante pequeño para que el asistente no lo sienta y lo
     * bastante mayor que cero para ser una autorización de verdad —
     * Cybersource no tokeniza sobre un importe nulo, y `CobroSolicitado` ni
     * siquiera deja construirlo.
     *
     * Se anula inmediatamente después, así que no llega a liquidarse; lo que
     * el asistente puede llegar a ver es una retención que desaparece.
     */
    public const IMPORTE_DE_VERIFICACION_CENTS = 100;

    /**
     * La versión del texto de consentimiento que se está enseñando hoy.
     *
     * Se guarda EN CADA FILA y no se lee de aquí para pintar: el día que el
     * texto cambie, esta constante sube de versión y las filas viejas siguen
     * diciendo a qué consintió cada quien. Un registro de consentimiento que
     * no dice a qué texto se consintió no demuestra nada.
     */
    public const VERSION_DEL_CONSENTIMIENTO = 'tarjetas-2026-08';

    public function __construct(
        private readonly CobrarConTarjeta $cobrar,
        private readonly BuscarTarjetaEnLaBoveda $buscarEnLaBoveda,
        private readonly AnularCobro $anular,
    ) {}

    public function __invoke(
        EventAppAccount $cuenta,
        string $transientTokenJwt,
        ?string $ip = null,
    ): AltaDeTarjeta {
        $referencia = 'EBR-TARJ-'.Str::upper(Str::random(10));

        try {
            $resultado = ($this->cobrar)(CobroSolicitado::conTarjetaNueva(
                referencia: $referencia,
                importeCents: self::IMPORTE_DE_VERIFICACION_CENTS,
                transientTokenJwt: $transientTokenJwt,
                idempotencyKey: (string) Str::uuid(),
                // La Merchant Defined Data que exige Visanet RD quiere un
                // identificador de cliente; el nuestro es la cuenta, que es
                // justo lo que agrupa las tarjetas.
                identificadorDeCliente: 'cuenta-'.$cuenta->id,
                ip: $ip,
            ));
        } catch (PaymentsException $e) {
            // El corte de transporte es el único fallo de pagos que este
            // camino traduce: significa exactamente «no sé si se cobró», que
            // es lo que el 409 le cuenta a la app.
            //
            // TODO LO DEMÁS SE DEJA SUBIR, y en particular `aprobado_sin_token`.
            // Ese es el peor estado del sistema —el asistente pagó y no quedó
            // credencial— y ya salió por Log::critical; convertirlo aquí en un
            // 409 educado lo taparía detrás de un «revisa tus tarjetas en un
            // momento» que invita a esperar algo que no va a aparecer nunca.
            if ($e->errorCode === 'fallo_de_transporte') {
                return AltaDeTarjeta::incierta();
            }

            throw $e;
        }

        $desenlace = $this->leer($resultado);

        if ($desenlace !== null) {
            return $desenlace;
        }

        // A partir de aquí el cobro está APROBADO y `CobrarConTarjeta`
        // garantiza que volvieron las dos piezas de la credencial: su guarda
        // de invariante revienta si no.
        $customer = (string) $resultado->customerTokenId;
        $instrumento = (string) $resultado->paymentInstrumentId;

        $enLaBoveda = $this->comoLaVeLaBoveda($customer, $instrumento, $resultado);

        // ─────────────────────────────────────────────────────────────────
        // LA FILA SE ESCRIBE ANTES DE ANULAR, Y ESE ORDEN ES LA DECISIÓN.
        // ─────────────────────────────────────────────────────────────────
        // Al revés —anular y después persistir— un fallo al escribir la fila
        // (la base caída, el despliegue a mitad, el único global de
        // `payment_instrument_id` chocando) dejaba un token VIVO y cobrable en
        // la bóveda sin nada que supiera ni que existe ni con qué borrarlo.
        // Es el mismo argumento con el que `comoLaVeLaBoveda()` decide NO
        // tirar la fila cuando la bóveda no contesta, aplicado aquí.
        //
        // Y si aun así la fila no se puede escribir, se devuelve el peso antes
        // de reventar: el token ya está perdido, y dejar además el cobro
        // pegado sería cobrarle al asistente por un fallo nuestro.
        try {
            $tarjeta = $this->persistir($cuenta, $customer, $instrumento, $enLaBoveda, $resultado, $referencia, $ip);
        } catch (Throwable $e) {
            $this->deshacerLaVerificacion($resultado, $referencia, null);

            throw $e;
        }

        $this->deshacerLaVerificacion($resultado, $referencia, $tarjeta);

        return AltaDeTarjeta::guardada($tarjeta);
    }

    /**
     * Traduce el desenlace del cobro, o `null` si hay que seguir guardando.
     *
     * El reparto no es el obvio, y por eso está aquí y no en un `if` del
     * controlador:
     *
     * - **Rechazado** es el único «me dijeron que no»: no se cobró y
     *   reintentar es gratis.
     * - **INVALID_REQUEST** también se cuenta como rechazo aunque su
     *   desenlace sea `Error`: Cybersource dice explícitamente que no llegó a
     *   procesar la petición, así que no hay cobro que temer. Es lo que sale
     *   con un `transientTokenJwt` caducado —quince minutos— y el asistente
     *   tiene que poder volver a intentarlo.
     * - **Pendiente** es incierto para nosotros, y no es una licencia: un
     *   `PENDING_AUTHENTICATION` pide un paso de 3DS que en este slice no
     *   está construido, así que la decisión final NO existe y decirle al
     *   asistente que reintente sería invitarle a un segundo cobro.
     * - **Desconocido** —un estado que Cybersource añada mañana— cae también
     *   en incierto: un estado que no entendemos no puede heredar el
     *   beneficio de la duda.
     */
    private function leer(ResultadoDeCobro $resultado): ?AltaDeTarjeta
    {
        if ($resultado->esAprobado()) {
            return null;
        }

        if ($resultado->esRechazado() || $resultado->estado === EstadoDeCobro::PeticionInvalida) {
            return AltaDeTarjeta::rechazada($this->motivoLegible($resultado));
        }

        Log::warning('[Tarjetas] la verificación quedó sin desenlace claro', $resultado->paraLog());

        return AltaDeTarjeta::incierta();
    }

    /**
     * Lo que se le enseña al asistente cuando le rechazan la tarjeta.
     *
     * Sale de `errorInformation.message`, que es texto para humanos, y no del
     * `reason`, que es un código crudo («INVALID_ACCOUNT») que no significa
     * nada para quien está delante del teléfono. Si no viene ninguno —pasa en
     * los rechazos escuetos— se dice algo cierto en vez de dejarlo en blanco:
     * el asistente necesita saber que puede probar con otra tarjeta.
     */
    private function motivoLegible(ResultadoDeCobro $resultado): string
    {
        return $resultado->mensaje
            ?? 'El banco no aceptó la tarjeta. Prueba con otra.';
    }

    /**
     * Los datos con los que la app pinta la tarjeta, preguntándoselos a la
     * bóveda.
     *
     * Si la bóveda no contesta NO se aborta el alta, y esa es una decisión.
     * La tarjeta ya está tokenizada y es cobrable: tirar la fila dejaría un
     * token vivo sin dueño —la tarjeta fantasma, del revés y peor, porque
     * nadie sabría ni que existe—. Así que se guarda con lo que se sabe: la
     * marca sale de la respuesta del cobro, que sí la trae
     * (`paymentInformation.card.type`, medido), y los cuatro dígitos y el
     * vencimiento se quedan en null. La app los tipa anulables por esto.
     */
    private function comoLaVeLaBoveda(
        string $customer,
        string $instrumento,
        ResultadoDeCobro $resultado,
    ): TarjetaEnLaBoveda {
        try {
            $tarjeta = ($this->buscarEnLaBoveda)($customer, $instrumento);

            if ($tarjeta !== null) {
                return $tarjeta;
            }

            Log::warning('[Tarjetas] la bóveda no encuentra la tarjeta recién tokenizada', [
                'payment_instrument' => '…'.mb_substr($instrumento, -4),
            ]);
        } catch (PaymentsException $e) {
            Log::warning('[Tarjetas] no se pudo leer la tarjeta recién tokenizada', [
                'payment_instrument' => '…'.mb_substr($instrumento, -4),
                'error' => $e->getMessage(),
            ]);
        }

        return new TarjetaEnLaBoveda(
            paymentInstrumentId: $instrumento,
            marca: MarcaDeTarjeta::desdeCybersource(
                $this->tipoDeTarjetaDelCobro($resultado)
            ),
            ultimos4: null,
            venceMes: null,
            venceAno: null,
            instrumentIdentifierId: $resultado->instrumentIdentifierId,
        );
    }

    /**
     * La marca según la respuesta del cobro. Es lo ÚNICO de la tarjeta que
     * trae: ni últimos 4 ni vencimiento (medido contra apitest el
     * 2026-08-07).
     */
    private function tipoDeTarjetaDelCobro(ResultadoDeCobro $resultado): mixed
    {
        $pago = $resultado->crudo['paymentInformation'] ?? null;

        if (! is_array($pago) || ! is_array($pago['card'] ?? null)) {
            return null;
        }

        return $pago['card']['type'] ?? null;
    }

    /**
     * Devuelve el peso de la verificación, y deja escrito si se devolvió.
     *
     * Es limpieza, no un paso del alta: para cuando esto corre la tarjeta ya
     * está guardada y usable, que es lo que el asistente pidió. Si la
     * anulación falla se anota y se sigue — reventar aquí le negaría la
     * tarjeta por un problema nuestro, y encima le dejaría el cobro igual,
     * porque el cobro ya ocurrió.
     *
     * ─────────────────────────────────────────────────────────────────────
     * EL RASTRO ES LA FILA, NO EL LOG.
     * ─────────────────────────────────────────────────────────────────────
     * `verification_voided_at` se llena SOLO cuando Cybersource contestó
     * VOIDED. Mientras siga en null hay un cargo real de RD$1 pegado a la
     * tarjeta de alguien, y esa fila aparece en
     * `EventAppCard::pendientesDeAnular()` con la referencia y el id de
     * transacción que necesitan `BuscarCobroPorReferencia` y `AnularCobro`
     * para resolverlo. Antes el único rastro era este mismo `Log::warning` y
     * una referencia que nacía y moría dentro de esta acción: para recuperar
     * un cargo atascado había que estar mirando el fichero ese día.
     *
     * `$tarjeta` es null en el único camino en que el cobro existe y la fila
     * no: cuando persistir reventó. Ahí no hay dónde anotar nada —la petición
     * sale 500 y el handler deja su propio rastro—, así que se hace lo único
     * que queda por hacer, que es intentar devolver el peso.
     */
    private function deshacerLaVerificacion(
        ResultadoDeCobro $resultado,
        string $referencia,
        ?EventAppCard $tarjeta,
    ): void {
        try {
            ($this->anular)((string) $resultado->transactionId, $referencia);
        } catch (PaymentsException $e) {
            Log::warning('[Tarjetas] queda pendiente devolver el cobro de verificación', [
                'referencia' => $referencia,
                'transaction_id' => $resultado->transactionId,
                'tarjeta' => $tarjeta?->getKey(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($tarjeta === null) {
            return;
        }

        $tarjeta->verification_voided_at = now();
        $tarjeta->save();
    }

    /**
     * La fila, y con ella el consentimiento.
     *
     * La primera tarjeta de una cuenta nace por defecto: si no, el asistente
     * guardaría una tarjeta y no tendría ninguna elegida, que es un estado
     * que no le sirve a nadie. Las siguientes NO se roban el puesto — marcar
     * otra es un gesto suyo, no un efecto lateral de añadir.
     *
     * Todo dentro de una transacción porque «¿hay ya alguna?» y «esta nace
     * por defecto» tienen que decidirse juntas: dos altas simultáneas leyendo
     * antes de que ninguna escriba dejarían dos tarjetas por defecto.
     */
    private function persistir(
        EventAppAccount $cuenta,
        string $customer,
        string $instrumento,
        TarjetaEnLaBoveda $enLaBoveda,
        ResultadoDeCobro $resultado,
        string $referencia,
        ?string $ip,
    ): EventAppCard {
        return DB::transaction(function () use ($cuenta, $customer, $instrumento, $enLaBoveda, $resultado, $referencia, $ip): EventAppCard {
            $esLaPrimera = ! EventAppCard::query()
                ->where('event_app_account_id', $cuenta->id)
                ->lockForUpdate()
                ->exists();

            /** @var EventAppCard $tarjeta */
            $tarjeta = EventAppCard::query()->create([
                'event_app_account_id' => $cuenta->id,
                'customer_token_id' => $customer,
                'payment_instrument_id' => $instrumento,
                'instrument_identifier_id' => $enLaBoveda->instrumentIdentifierId ?? $resultado->instrumentIdentifierId,
                'brand' => $enLaBoveda->marca,
                'last4' => $enLaBoveda->ultimos4,
                'exp_month' => $enLaBoveda->venceMes,
                'exp_year' => $enLaBoveda->venceAno,
                'is_default' => $esLaPrimera,
                // El cobro de verificación, con su anulación todavía por
                // hacer: nace pendiente y se apaga cuando Cybersource dice
                // VOIDED. Ver `deshacerLaVerificacion()`.
                'verification_reference' => $referencia,
                'verification_transaction_id' => $resultado->transactionId,
                'verification_voided_at' => null,
                'consent_at' => now(),
                'consent_version' => self::VERSION_DEL_CONSENTIMIENTO,
                'consent_ip' => $ip,
            ]);

            return $tarjeta;
        });
    }
}
