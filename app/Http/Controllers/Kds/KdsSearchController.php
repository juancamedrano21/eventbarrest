<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kds;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KdsDevice;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Kitchen\Queries\KitchenBoard;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\OrderLine;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El «¿y lo mío?».
 *
 * Alguien lleva rato esperando y viene a preguntar. Su tarjeta puede no
 * estar en el tablero por dos motivos perfectamente normales: se marcó
 * lista hace más de veinte minutos y se cayó sola, o su comanda es de esta
 * mañana y la ventana del tablero solo mira doce horas atrás. Sin esta
 * pantalla, la única respuesta posible del puesto es «no me aparece», que
 * es exactamente lo que no se le puede decir a alguien que ya pagó.
 *
 * Por eso busca en TODO EL DÍA del puesto —y nunca en menos que la ventana
 * del tablero, pase lo que pase con el reloj—, y por eso responde con HORAS
 * y no con estados a secas: «lista a las 8:14» cierra la conversación,
 * «lista» la abre.
 *
 * Se busca por número público y por nombre del cliente, que son las dos
 * cosas que la persona puede decir de memoria mirando su recibo.
 */
class KdsSearchController extends Controller
{
    /** Quien busca tiene a alguien delante: si hay más de veinte, el término está mal. */
    private const MAXIMO = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->attributes->get('kds_device');

        abort_unless($device instanceof KdsDevice, 403);

        $data = $request->validate([
            'q' => ['required', 'string', 'max:60'],
        ]);

        $termino = trim((string) $data['q']);

        if ($termino === '') {
            return response()->json(['results' => [], 'server_time' => now()->toIso8601String()]);
        }

        // El número se canta de muchas formas —«el V0012», «el 12», «el
        // 0012»— y todas son el mismo. Se comparan los dígitos contra la
        // columna, que además está indexada; el prefijo de canal lo pone
        // publicNumber() al pintar.
        $digitos = (string) preg_replace('/\D/', '', $termino);

        // El día del puesto en hora local: a las once de la noche de un
        // festival, «hoy» sigue siendo hoy, y en UTC ya sería mañana.
        $inicioDelDia = today((string) config('app.business_timezone'))->utc();

        // Y NUNCA MÁS TARDE QUE LA VENTANA DEL TABLERO. El día de calendario
        // se reinicia a las 00:00, que en un festival es la hora punta: la
        // búsqueda se vaciaba de golpe mientras el tablero —doce horas
        // rodantes— seguía enseñando la misma tarjeta. La cocinera veía la
        // comanda en la pantalla y esta pantalla le contestaba que esa venta
        // no existe, que es justo la respuesta que no se le puede dar a
        // alguien que ya pagó. Lo que se busca CONTIENE lo que se pinta.
        $ventanaDelTablero = KitchenBoard::inicioDeLaVentana();

        $desde = $inicioDelDia->lessThan($ventanaDelTablero) ? $inicioDelDia : $ventanaDelTablero;

        $ordenes = Order::query()
            ->whereIn('operating_unit_id', $device->unidadesVigiladas())
            ->where('status', OrderStatus::Paid)
            ->where('paid_at', '>=', $desde)
            ->where(function (Builder $consulta) use ($termino, $digitos): void {
                // Los comodines del propio término se escapan: un cliente
                // que se llame «100%» no puede convertirse en «todas».
                $consulta->where('customer_name', 'like', '%'.addcslashes($termino, '%_\\').'%');

                if ($digitos !== '') {
                    $consulta->orWhere('order_number', (int) $digitos);
                }
            })
            ->with(['lines', 'operatingUnit'])
            ->orderByDesc('paid_at')
            ->limit(self::MAXIMO)
            ->get();

        if ($ordenes->isEmpty()) {
            return response()->json(['results' => [], 'server_time' => now()->toIso8601String()]);
        }

        // Una sola consulta para las comandas de todas las ventas
        // encontradas: nada dentro del bucle.
        $comandas = KitchenTicket::query()
            ->whereIn('order_id', $ordenes->modelKeys())
            ->get()
            ->keyBy(fn (KitchenTicket $comanda): string => $comanda->order_id.':'.$comanda->area->value);

        $resultados = $ordenes->map(fn (Order $orden): array => [
            'order_id' => $orden->id,
            'number' => $orden->publicNumber(),
            // customer_name no está en el docblock de Order, que no es mío.
            'customer_name' => is_string($nombre = $orden->getAttribute('customer_name')) ? $nombre : null,
            'paid_at' => $orden->paid_at?->toIso8601String(),
            'device_sold_at' => $orden->device_sold_at?->toIso8601String(),
            'areas' => $this->areas($orden, $comandas, $device->area),
        ])->all();

        return response()->json([
            'results' => $resultados,
            // La misma hora del servidor que manda en el tablero: los
            // relojes de la pantalla se calculan todos contra ella.
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Lo que esta venta despacha por cada área, con la hora de cada paso.
     *
     * @param  EloquentCollection<string, KitchenTicket>  $comandas
     * @return array<int, array<string, mixed>>
     */
    private function areas(Order $orden, EloquentCollection $comandas, ?DispatchArea $delDispositivo): array
    {
        $porDefecto = DispatchArea::porDefecto($orden->operatingUnit?->kind);
        $unidades = [];

        foreach ($orden->lines as $linea) {
            $area = ($linea->dispatch ?? $porDefecto)->value;
            $unidades[$area][] = $linea;
        }

        $areas = [];

        // En el orden declarado del enum, como el tablero: dos lecturas
        // seguidas tienen que decir lo mismo.
        foreach (DispatchArea::cases() as $caso) {
            if (! isset($unidades[$caso->value])) {
                continue;
            }

            if ($delDispositivo !== null && $delDispositivo !== $caso) {
                continue;
            }

            $comanda = $comandas->get($orden->id.':'.$caso->value);

            $areas[] = [
                'area' => $caso->value,
                // Sin fila y con fila en pendiente son el MISMO estado: la
                // vuelta atrás no borra nada, deja la fila en pending.
                'status' => ($comanda instanceof KitchenTicket ? $comanda->status : KitchenTicketStatus::Pending)->value,
                'items_count' => (int) round(collect($unidades[$caso->value])
                    ->sum(fn (OrderLine $linea): float => (float) $linea->quantity)),
                'started_at' => $comanda instanceof KitchenTicket ? $comanda->started_at?->toIso8601String() : null,
                'ready_at' => $comanda instanceof KitchenTicket ? $comanda->ready_at?->toIso8601String() : null,
                'lines' => collect($unidades[$caso->value])->map(fn (OrderLine $linea): array => [
                    'quantity' => (float) $linea->quantity,
                    'product_name' => $linea->product_name,
                    'notes' => $linea->notes,
                ])->all(),
            ];
        }

        return $areas;
    }
}
