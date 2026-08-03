<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kds;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Kitchen\Actions\AdvanceKitchenTicket;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Exceptions\KitchenException;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Sales\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El toque en la tarjeta: mueve una comanda de estado.
 *
 * SE DIRECCIONA POR (ORDEN, ÁREA) Y NO POR EL id DE LA COMANDA. No es una
 * preferencia de estilo: una comanda pendiente NO TIENE FILA —pendiente es
 * la ausencia de fila en kitchen_tickets— y el primer toque, el que más se
 * da en toda la noche, es justamente el que ocurre cuando todavía no hay
 * id que poner en la URL.
 *
 * El cuerpo trae `from` y `to`, y el `from` no es decorativo: es el control
 * optimista. La tablet dice de dónde CREÍA que venía la comanda, y si el
 * mundo ya no está ahí recibe un 409 con la fila vigente dentro en vez de
 * pisar el trabajo del compañero. Sin él, la cocinera marca LISTA y el
 * ayudante —con una pantalla de hace tres segundos que aún dice EN
 * PROCESO— la deshace sin enterarse de que la deshizo, porque volver atrás
 * es un movimiento legal y la matriz de transiciones lo deja pasar.
 */
class KdsTicketController extends Controller
{
    /**
     * @param  int  $order  Llega como int y se busca aquí dentro: el
     *                      route-model-binding corre ANTES de que el
     *                      middleware fije el contexto y daría 404 siempre.
     */
    public function estado(Request $request, int $order, string $area): JsonResponse
    {
        $device = $request->attributes->get('kds_device');

        abort_unless($device instanceof KdsDevice, 403);

        $data = $request->validate([
            'from' => ['required', Rule::enum(KitchenTicketStatus::class)],
            'to' => ['required', Rule::enum(KitchenTicketStatus::class)],
        ]);

        $areaTocada = DispatchArea::tryFrom($area)
            ?? throw new KitchenException('Ese despacho no existe.', 'kds_area_desconocida', 404);

        // Con los scopes puestos: la venta de otra cuenta o de otro comercio
        // no existe, y findOrFail devuelve el 404 sin que haya que decidir
        // nada. Lo que los scopes NO separan es un puesto del de al lado
        // dentro del MISMO comercio, y de eso se encarga la línea siguiente.
        $orden = Order::query()->findOrFail($order);

        if (! in_array($orden->operating_unit_id, $device->unidadesVigiladas(), true)) {
            throw KitchenException::ordenDeOtroPuesto();
        }

        // Una tablet enrolada solo para la barra no marca platos de cocina.
        // 404 y no 403 por la misma razón que el resto: lo que no sale en tu
        // tablero no existe para ti, y probar áreas a mano no cuenta nada.
        if ($device->area !== null && $device->area !== $areaTocada) {
            throw new KitchenException(
                'Esta tablet no atiende ese despacho.',
                'kds_area_ajena',
                404,
            );
        }

        $desde = KitchenTicketStatus::coerce((string) $data['from']);
        $hasta = KitchenTicketStatus::coerce((string) $data['to']);

        try {
            $comanda = app(AdvanceKitchenTicket::class)($orden, $areaTocada, $desde, $hasta, $device->id);
        } catch (KitchenException $choque) {
            // El 409 se atiende AQUÍ y no en el render global porque la
            // tablet necesita la fila vigente dentro: perder la carrera
            // significa que su pantalla está vieja, y devolverle solo el
            // código la obligaría a un polling extra para enterarse de a
            // qué estado tiene que repintar la tarjeta que acaba de tocar.
            if ($choque->errorCode !== 'kitchen_status_changed') {
                throw $choque;
            }

            return response()->json([
                'code' => $choque->errorCode,
                'message' => $choque->getMessage(),
                'ticket' => $this->vigente($orden->id, $areaTocada),
            ], 409);
        }

        return response()->json(['ticket' => $this->fila($comanda)]);
    }

    /**
     * El estado real de la comanda ahora mismo, incluida la posibilidad de
     * que no exista: sin fila es PENDIENTE, no es un hueco.
     *
     * @return array<string, mixed>
     */
    private function vigente(int $orderId, DispatchArea $area): array
    {
        $comanda = KitchenTicket::query()
            ->where('order_id', $orderId)
            ->where('area', $area)
            ->first();

        if ($comanda !== null) {
            return $this->fila($comanda);
        }

        return [
            'id' => null,
            'order_id' => $orderId,
            'area' => $area->value,
            'status' => KitchenTicketStatus::Pending->value,
            'started_at' => null,
            'ready_at' => null,
        ];
    }

    /**
     * El id puede ser null y no es un error: AdvanceKitchenTicket devuelve
     * una comanda SIN persistir cuando se le pide dejar pendiente algo que
     * nadie había tocado. La ausencia de fila ya era el estado pedido, así
     * que no se inventa ninguna.
     *
     * @return array<string, mixed>
     */
    private function fila(KitchenTicket $comanda): array
    {
        return [
            'id' => $comanda->exists ? $comanda->id : null,
            'order_id' => $comanda->order_id,
            'area' => $comanda->area->value,
            'status' => $comanda->status->value,
            'started_at' => $comanda->started_at?->toIso8601String(),
            'ready_at' => $comanda->ready_at?->toIso8601String(),
        ];
    }
}
