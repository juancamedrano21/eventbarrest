<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Actions;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Mueve una comanda de estado: el toque en la tarjeta del tablero.
 *
 * Se direcciona por (orden, área) y NO por el id de la comanda, porque una
 * comanda pendiente todavía no tiene fila —pendiente es la AUSENCIA de fila—
 * y aun así la tablet tiene que poder tocarla. La fila nace aquí, en el
 * primer toque, y no antes: no hay observer que la cree al vender ni job que
 * pueda quedarse en la cola sin correr.
 *
 * El control es OPTIMISTA POR ESTADO: quien toca dice de dónde creía que
 * venía la comanda, y si el mundo ya no está ahí recibe un 409 en vez de
 * pisar el trabajo del compañero. Esto NO lo puede dar la matriz de
 * transiciones, porque volver atrás es legal: sin el control, la cocinera
 * marca LISTA y el ayudante —con una pantalla de hace tres segundos que
 * todavía dice EN PROCESO— la deshace sin enterarse de que la deshizo. La
 * matriz dice qué movimientos existen; el estado de origen dice si este
 * movimiento sigue teniendo sentido.
 */
class AdvanceKitchenTicket
{
    public function __invoke(
        Order $order,
        DispatchArea $area,
        KitchenTicketStatus $desde,
        KitchenTicketStatus $hasta,
        ?int $deviceId = null,
    ): KitchenTicket {
        $orderId = $order->id;

        return DB::transaction(function () use ($orderId, $area, $desde, $hasta, $deviceId): KitchenTicket {
            // Con scopes: la venta de otra cuenta o de otro comercio no
            // existe para quien toca, y lo que no existe da 404 y no 403.
            $order = Order::query()->whereKey($orderId)->first()
                ?? throw KitchenException::ordenDeOtroPuesto();

            // Con lock: dos tablets sobre el mismo puesto se serializan aquí
            // y la segunda lee el estado ya comiteado por la primera.
            $ticket = KitchenTicket::query()
                ->where('order_id', $order->id)
                ->where('area', $area)
                ->lockForUpdate()
                ->first();

            // No haber encontrado fila no es un fallo: es el estado
            // pendiente dicho en el idioma de esta tabla.
            $actual = $ticket === null ? KitchenTicketStatus::Pending : $ticket->status;

            if ($actual !== $desde) {
                throw KitchenException::estadoCambiado($actual);
            }

            if ($hasta !== $actual && ! $actual->canTransitionTo($hasta)) {
                throw KitchenException::transicionImposible($actual, $hasta);
            }

            // Todo lo que ve la cocina nace cobrado: PosOrderController
            // envuelve la venta y su cobro en una sola transacción, así que
            // una orden abierta aquí es una venta a medio teclear.
            if ($order->status !== OrderStatus::Paid) {
                throw KitchenException::ordenNoCobrada();
            }

            // UNIDADES, no líneas. Es la misma cuenta que hace KitchenBoard
            // para las comandas que todavía no tienen fila, y su docblock lo
            // exige: la que sale del tablero y la que se congela aquí tienen
            // que decir lo mismo, o «3 unidades» se convertirá en «2» en
            // cuanto alguien toque la tarjeta.
            $lineas = $order->lines()->where('dispatch', $area)->get(['quantity']);
            $items = (int) round($lineas->sum(fn ($linea): float => (float) $linea->quantity));

            if ($lineas->isEmpty()) {
                throw KitchenException::areaSinLineas($area);
            }

            // Repetir el mismo destino no es un error: es el reintento de una
            // tablet que perdió la respuesta por el wifi del festival.
            if ($hasta === $actual) {
                return $ticket ?? $this->comandaSinTocar($order, $area, $items);
            }

            // Solo el nacimiento puede chocar contra el único: la identidad
            // de una fila ya viva es inmutable, así que su UPDATE no compite
            // con nadie por (cuenta, orden, área).
            $naciendo = $ticket === null;
            $ticket ??= $this->nuevaComanda($order, $area, $items);

            $ticket->status = $hasta;
            $this->sellarHoras($ticket, $actual, $hasta, $deviceId);

            try {
                $ticket->saveTransition();
            } catch (UniqueConstraintViolationException $exception) {
                if (! $naciendo) {
                    throw $exception;
                }

                return $this->resolverCarrera($order, $area, $hasta, $exception);
            }

            return $ticket;
        }, 3);
    }

    /**
     * Dos tablets tocaron a la vez una comanda que aún no existía. El lock
     * no protege de esto: no se puede bloquear una fila ausente, y el único
     * de (cuenta, orden, área) es justamente el backstop que lo caza.
     *
     * Perder la carrera se cuenta como lo que es —el mundo cambió debajo—
     * salvo que la ganadora nos haya dejado exactamente donde queríamos ir,
     * en cuyo caso los dos toques querían lo mismo y no hay nada que avisar.
     */
    private function resolverCarrera(
        Order $order,
        DispatchArea $area,
        KitchenTicketStatus $hasta,
        UniqueConstraintViolationException $exception,
    ): KitchenTicket {
        $gemela = KitchenTicket::query()
            ->where('order_id', $order->id)
            ->where('area', $area)
            ->first();

        if ($gemela === null) {
            // No chocó contra su gemela sino contra otra cosa: disfrazarlo
            // de carrera escondería el fallo real.
            throw $exception;
        }

        if ($gemela->status !== $hasta) {
            throw KitchenException::estadoCambiado($gemela->status);
        }

        return $gemela;
    }

    /**
     * La comanda que nadie ha tocado todavía. No tiene fila y no se la
     * inventamos: la ausencia ES el estado pendiente, y escribir una fila
     * «pendiente» rompería la única regla que hace imposible perder una
     * comanda. Se devuelve el objeto sin persistir porque pedir «déjala
     * pendiente» sobre una venta que nunca entró al tablero ya está cumplido.
     */
    private function comandaSinTocar(Order $order, DispatchArea $area, int $items): KitchenTicket
    {
        $ticket = $this->nuevaComanda($order, $area, $items);
        $ticket->status = KitchenTicketStatus::Pending;

        return $ticket;
    }

    /**
     * La fila recién nacida, todavía sin estado: se lo pone la transición.
     *
     * vendor_id va EXPLÍCITO desde la orden y no desde el contexto: quien
     * toca el tablero es una tablet enrolada, y VendorScope falla abierto —
     * sin comercio activo nadie rellenaría la columna, que es NOT NULL
     * precisamente para ser el último backstop de la base. tenant_id sí lo
     * pone el trait, porque TenantScope falla cerrado y sin el contexto
     * correcto no habríamos podido leer la orden dos líneas más arriba.
     */
    private function nuevaComanda(Order $order, DispatchArea $area, int $items): KitchenTicket
    {
        $vendorId = $order->getAttribute('vendor_id');

        if ($vendorId === null) {
            throw new KitchenException(
                'Esta venta no pertenece a ningún comercio y el tablero de cocina es de un puesto.',
                'kitchen_order_without_vendor',
            );
        }

        $ticket = new KitchenTicket([
            'operating_unit_id' => $order->operating_unit_id,
            'order_id' => $order->id,
            'area' => $area,
            'items_count' => $items,
        ]);

        $ticket->setAttribute('vendor_id', $vendorId);

        return $ticket;
    }

    /**
     * Los sellos de hora y de tablet que acompañan al nuevo estado.
     *
     * Volver atrás BORRA la marca que se deshace, y no por pulcritud: si
     * ready_at sobreviviera a un «esto no estaba listo», el informe de
     * tiempos mediría un instante que ya nadie sostiene, y lo haría en
     * silencio. La marca del paso que NO se deshace se queda: quien volvió
     * de Lista a En proceso sigue habiendo empezado cuando empezó.
     */
    private function sellarHoras(
        KitchenTicket $ticket,
        KitchenTicketStatus $desde,
        KitchenTicketStatus $hasta,
        ?int $deviceId,
    ): void {
        if ($hasta === KitchenTicketStatus::InProgress) {
            if ($desde === KitchenTicketStatus::Pending) {
                $ticket->started_at = now();
                $ticket->started_by_device_id = $deviceId;
            } else {
                $ticket->ready_at = null;
                $ticket->ready_by_device_id = null;
            }
        }

        if ($hasta === KitchenTicketStatus::Ready) {
            $ticket->ready_at = now();
            $ticket->ready_by_device_id = $deviceId;
        }

        if ($hasta === KitchenTicketStatus::Pending) {
            $ticket->started_at = null;
            $ticket->started_by_device_id = null;
        }
    }
}
