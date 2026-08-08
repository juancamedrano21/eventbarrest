<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Actions;

use App\Domains\EventApp\Models\EventAppCard;
use App\Domains\Payments\Actions\BorrarClienteDeLaBoveda;
use App\Domains\Payments\Actions\BorrarTarjetaDeLaBoveda;
use App\Domains\Payments\Exceptions\PaymentsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quitar UNA tarjeta: primero de la bóveda de Cybersource, después de aquí.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SI LA BÓVEDA FALLA, LA FILA NO SE BORRA. ESE ES TODO EL DISEÑO.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * El orden cómodo sería borrar la fila y encolar el borrado remoto: la app
 * responde rápido y el asistente ve su tarjeta desaparecer. Y es el orden
 * equivocado. Una fila que desaparece con el token vivo es una tarjeta que el
 * asistente CREE haber quitado y que se le puede seguir cobrando — y encima
 * ya no queda dónde mirar el id, porque vivía en la fila que se borró.
 *
 * Así que la bóveda manda: si `BorrarTarjetaDeLaBoveda` revienta, esta acción
 * revienta con ella y la fila sigue ahí. El asistente ve un error y su
 * tarjeta en la lista, que es la verdad. Reintentar es seguro: un token que
 * ya no está devuelve 404 o 410 y eso cuenta como borrado.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRECONDICIÓN DEL CUARTO SLICE: EL DEFECTO EN TMS, NO SOLO EL DE AQUÍ.
 * ─────────────────────────────────────────────────────────────────────────
 * El relevo de la tarjeta por defecto que hace el paso 3 es SOLO LOCAL. El
 * contrato pide además reasignar el `defaultPaymentInstrument` del customer en
 * Cybersource ANTES de borrar, porque el TMS rechaza borrar el instrumento
 * marcado como defecto de un customer que todavía tiene otros.
 *
 * Hoy eso no muerde y por eso no está construido: cada alta estrena su propio
 * customer con un solo instrumento dentro (medido — ver
 * `GuardarTarjetaDelAsistente`), así que el instrumento que se borra nunca
 * tiene hermanos. **El día que el cuarto slice consiga colgar N tarjetas del
 * mismo customer, esto pasa a ser obligatorio y hay que construirlo ANTES**:
 * sin él, el DELETE devolvería 4xx, esta acción reventaría, y esa tarjeta
 * concreta quedaría IMPOSIBLE de borrar para el asistente — fila viva, token
 * vivo y un botón que nunca funciona. Lo que falta es un
 * `PATCH /tms/v2/customers/{c}/payment-instruments/{pi}` con `default: true`
 * sobre la heredera, ejecutado antes del paso 1 y comprobado contra el sandbox
 * como todo lo demás de este slice.
 */
class OlvidarTarjetaDelAsistente
{
    public function __construct(
        private readonly BorrarTarjetaDeLaBoveda $borrarTarjeta,
        private readonly BorrarClienteDeLaBoveda $borrarCliente,
    ) {}

    public function __invoke(EventAppCard $tarjeta): void
    {
        $customer = $tarjeta->customer_token_id;

        // 1. La credencial con la que se cobra. Estricto: si esto no se
        //    completa, aquí no se borra nada.
        ($this->borrarTarjeta)($customer, $tarjeta->payment_instrument_id);

        // 2. El cliente que la agrupaba, si no le queda ninguna tarjeta
        //    nuestra. Se hace explícitamente y no confiando en ninguna
        //    cascada, que es la regla del slice; y se hace en modo blando
        //    porque un cliente SIN instrumentos ya no cobra nada: dejarlo
        //    huérfano es basura en la bóveda, no una tarjeta viva.
        $ultimaDeEseCliente = ! EventAppCard::query()
            ->where('customer_token_id', $customer)
            ->whereKeyNot($tarjeta->getKey())
            ->exists();

        if ($ultimaDeEseCliente) {
            $this->intentarBorrarElCliente($customer);
        }

        // 3. Y ahora sí, la fila — con el relevo de la que estaba por
        //    defecto, en la misma transacción.
        $this->borrarLaFila($tarjeta);
    }

    private function intentarBorrarElCliente(string $customer): void
    {
        try {
            ($this->borrarCliente)($customer);
        } catch (PaymentsException $e) {
            Log::warning('[Tarjetas] la tarjeta se borró pero su cliente de la bóveda queda huérfano', [
                'customer' => '…'.mb_substr($customer, -4),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Borra la fila y, si la que se va era la de por defecto y quedan otras,
     * asciende a la más antigua.
     *
     * Una cuenta con tarjetas y ninguna por defecto es un estado que no le
     * sirve a nadie: la app tendría que elegir por su cuenta y elegiría
     * distinto que el servidor. Las dos escrituras van juntas en una
     * transacción por lo mismo que van juntas al dar de alta.
     */
    private function borrarLaFila(EventAppCard $tarjeta): void
    {
        DB::transaction(function () use ($tarjeta): void {
            $eraLaPorDefecto = $tarjeta->is_default;
            $cuentaId = $tarjeta->event_app_account_id;

            $tarjeta->delete();

            if (! $eraLaPorDefecto) {
                return;
            }

            $heredera = EventAppCard::query()
                ->where('event_app_account_id', $cuentaId)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($heredera === null) {
                return;
            }

            $heredera->is_default = true;
            $heredera->save();
        });
    }
}
