<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Actions;

use App\Domains\EventApp\Models\EventAppAccount;
use App\Domains\EventApp\Models\EventAppCard;
use App\Domains\Payments\Actions\BorrarClienteDeLaBoveda;
use App\Domains\Payments\Actions\BorrarTarjetaDeLaBoveda;
use App\Domains\Payments\Exceptions\PaymentsException;
use Illuminate\Support\Facades\Log;

/**
 * Vacía la bóveda de un asistente que borra su cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NO SE CONFÍA EN LA CASCADA DE CYBERSOURCE, AUNQUE SE HAYA MEDIDO QUE EXISTE.
 * ─────────────────────────────────────────────────────────────────────────
 * Borrar el `customer` deja sus payment instruments inalcanzables —probado
 * contra apitest el 2026-08-07: un `GET` posterior devuelve 410—, pero eso NO
 * está documentado, así que no es una promesa sobre la que construir un
 * borrado que Apple exige (5.1.1(v)). Aquí se borra **cada payment instrument
 * uno a uno y después cada cliente**, y la cascada, si funciona, se limita a
 * no hacer nada.
 *
 * Y NO SE BORRA LA FILA LOCAL ANTES QUE EL TOKEN, ni siquiera aquí donde toda
 * la cuenta se va: el mismo argumento de `OlvidarTarjetaDelAsistente`, con la
 * agravante de que después del borrado no queda NADIE que sepa qué tokens
 * había. Si la bóveda no contesta, esta acción revienta y la cuenta NO se
 * borra: el asistente ve un error y vuelve a intentarlo, que es infinitamente
 * mejor que quedarse sin cuenta y con la tarjeta cobrable.
 *
 * Es reanudable a propósito: cada tarjeta se borra fuera y dentro antes de
 * pasar a la siguiente, así que un fallo a mitad deja las anteriores
 * limpias en los dos sitios y el reintento sigue por donde iba.
 */
class OlvidarTarjetasDeLaCuenta
{
    public function __construct(
        private readonly BorrarTarjetaDeLaBoveda $borrarTarjeta,
        private readonly BorrarClienteDeLaBoveda $borrarCliente,
    ) {}

    public function __invoke(EventAppAccount $cuenta): void
    {
        /** @var list<EventAppCard> $tarjetas */
        $tarjetas = EventAppCard::query()
            ->where('event_app_account_id', $cuenta->id)
            ->orderBy('id')
            ->get()
            ->all();

        if ($tarjetas === []) {
            return;
        }

        $clientes = [];

        foreach ($tarjetas as $tarjeta) {
            $clientes[$tarjeta->customer_token_id] = true;

            // Estricto: una excepción aquí aborta el borrado de la cuenta.
            ($this->borrarTarjeta)($tarjeta->customer_token_id, $tarjeta->payment_instrument_id);

            $tarjeta->delete();
        }

        // Los clientes DISTINTOS, en segundo lugar. Hoy hay uno por tarjeta
        // (ver GuardarTarjetaDelAsistente: adjuntar a un customer existente
        // devuelve 400 y la forma buena llega con la captura real), y mañana
        // habrá uno por asistente: las dos cosas se recorren igual.
        //
        // Y CADA UNO SE PREGUNTA SI LE QUEDA DUEÑO, exactamente igual que en
        // `OlvidarTarjetaDelAsistente`. La pregunta es GLOBAL y no por cuenta
        // a propósito: borrar un customer en el TMS deja sus payment
        // instruments inalcanzables (410, medido), así que el día que dos
        // cuentas compartan customer —que es el plan— borrar una mataría la
        // tarjeta de la otra dejándole la fila puesta y la lista pintándola:
        // la tarjeta fantasma que todo el slice existe para evitar. Aquí no
        // pasa hoy porque cada alta estrena su propio customer; la asimetría
        // se cierra ahora porque el día que muerda no habría forma de
        // enterarse.
        foreach (array_keys($clientes) as $customer) {
            $leQuedaOtraTarjeta = EventAppCard::query()
                ->where('customer_token_id', $customer)
                ->exists();

            if ($leQuedaOtraTarjeta) {
                continue;
            }

            $this->intentarBorrarElCliente($customer);
        }
    }

    /**
     * En modo blando, y solo este paso: un cliente al que ya se le quitaron
     * todos los instrumentos no puede cobrar nada. Dejarlo huérfano es basura
     * en la bóveda; impedir por él que alguien borre su cuenta sería negarle
     * un derecho por un residuo.
     */
    private function intentarBorrarElCliente(string $customer): void
    {
        try {
            ($this->borrarCliente)($customer);
        } catch (PaymentsException $e) {
            Log::warning('[Tarjetas] la cuenta se borró y su cliente de la bóveda queda huérfano', [
                'customer' => '…'.mb_substr($customer, -4),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
