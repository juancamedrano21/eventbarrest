<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kds;

use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Kitchen\Queries\KitchenBoard;
use App\Domains\Kitchen\Queries\KitchenLineView;
use App\Domains\Kitchen\Queries\KitchenTicketView;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El tablero completo del puesto, tal y como lo pinta la tablet.
 *
 * Es un SNAPSHOT y no un diff. La tablet pregunta cada pocos segundos y
 * recibe el tablero entero o un 304: no hay lista de cambios que aplicar,
 * ni número de secuencia que pueda desincronizarse, ni un «me perdí un
 * evento» del que haya que recuperarse. Una pantalla que se quedó sin wifi
 * medio minuto vuelve al estado correcto con la primera respuesta buena,
 * sin saber siquiera que se lo perdió. Esa es toda la razón por la que aquí
 * no hay websockets.
 *
 * EL ETag SE CALCULA SIN server_time, Y ESE ES EL DETALLE QUE LO DECIDE
 * TODO. La hora del servidor viaja en la respuesta porque una tablet barata
 * con el reloj corrido pintaría esperas absurdas —«lleva 40 minutos» sobre
 * una comanda de hace dos— y todos los cronómetros de la pantalla se
 * calculan contra ella. Pero si esa hora entrara en el hash, el ETag
 * cambiaría cada segundo, el 304 no ocurriría JAMÁS y cada tablet se
 * descargaría el tablero entero cada dos segundos durante toda la noche. Se
 * hashea el cuerpo, se añade la hora después.
 *
 * (Por lo mismo, KitchenTicketView devuelve marcas de tiempo y ningún
 * segundo transcurrido: el cronómetro lo pinta el cliente.)
 */
class KdsBoardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $device = $request->attributes->get('kds_device');

        // Detrás de kds.device esto está siempre; el instanceof es para el
        // analizador, que solo ve un mixed saliendo de los atributos.
        abort_unless($device instanceof KdsDevice, 403);

        $tarjetas = app(KitchenBoard::class)->forUnits($device->unidadesVigiladas(), $device->area);

        $cuerpo = [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'area' => $device->area?->value,
            ],
            'outlet' => [
                'id' => $device->operating_unit_id,
                'name' => $device->unit?->name,
            ],
            'tickets' => $tarjetas->map(
                fn (KitchenTicketView $tarjeta): array => $this->tarjeta($tarjeta),
            )->all(),
        ];

        // Débil (W/) porque lo que se compara es el SIGNIFICADO del tablero,
        // no el byte: dos respuestas con la misma comanda en el mismo estado
        // son la misma pantalla aunque server_time las separe.
        $etag = 'W/"'.sha1((string) json_encode($cuerpo)).'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(304)->header('ETag', $etag);
        }

        return response()
            ->json($cuerpo + ['server_time' => now()->toIso8601String()])
            ->header('ETag', $etag);
    }

    /**
     * @return array<string, mixed>
     */
    private function tarjeta(KitchenTicketView $tarjeta): array
    {
        return [
            // Se direcciona por (orden, área) y no por el id de la comanda,
            // porque una comanda pendiente todavía no tiene fila. Es lo que
            // la tablet devuelve al tocar la tarjeta.
            'order_id' => $tarjeta->orderId,
            'area' => $tarjeta->area->value,
            'status' => $tarjeta->status->value,
            'number' => $tarjeta->numero,
            'customer_name' => $tarjeta->customerName,
            'items_count' => $tarjeta->itemsCount,
            'paid_at' => $tarjeta->paidAt->toIso8601String(),
            'device_sold_at' => $tarjeta->deviceSoldAt?->toIso8601String(),
            // Desde cuándo espera el cliente: manda el reloj del cajero
            // cuando lo hay, que es el de verdad.
            'waiting_since' => $tarjeta->esperaDesde()->toIso8601String(),
            // La venta se cobró hace rato y acaba de aparecer aquí (el POS
            // estaba sin cobertura). La tarjeta lo dice para que nadie la
            // trate como recién llegada.
            'late' => $tarjeta->llegoTarde(),
            'started_at' => $tarjeta->startedAt?->toIso8601String(),
            'ready_at' => $tarjeta->readyAt?->toIso8601String(),
            // Cómo va la OTRA mitad de la misma venta: sin esto se entrega
            // media orden y el cliente vuelve a la cola.
            'sibling_status' => $tarjeta->estadoHermano?->value,
            'refunded_cents' => $tarjeta->refundedCents,
            'lines' => $tarjeta->lines->map(fn (KitchenLineView $linea): array => [
                'quantity' => $linea->cantidad,
                'product_name' => $linea->productName,
                // Lo primero que hay que mirar de la línea: «sin cebolla».
                'notes' => $linea->notes,
            ])->all(),
        ];
    }
}
