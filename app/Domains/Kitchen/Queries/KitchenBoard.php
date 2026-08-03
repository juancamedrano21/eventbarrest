<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use App\Domains\Kitchen\Models\KitchenTicket;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\OrderLine;
use App\Domains\Sales\Models\Refund;
use Illuminate\Support\Collection;

/**
 * El tablero de un puesto: `orders LEFT JOIN kitchen_tickets`.
 *
 * Toda la pieza descansa en una sola idea: PENDIENTE ES LA AUSENCIA DE
 * FILA. Las tarjetas no se leen de kitchen_tickets, se leen de las VENTAS
 * COBRADAS, y la tabla de comandas solo aporta el estado de las que alguien
 * ya tocó. Por eso no hay observer que cree la fila al cobrar, ni job que
 * pueda quedarse muerto en la cola, ni backfill que correr al desplegar, ni
 * comando reconciliador que alguien tenga que acordarse de agendar: la
 * única forma de que un plato no salga en cocina es que no exista la venta.
 *
 * Las áreas de cada venta se derivan de `order_lines.dispatch`, que se
 * congeló al vender. Una línea con dispatch NULL —residuo de antes de que
 * existiera la columna, o de un producto ya borrado— no se descarta: cae al
 * área que declare `operating_units.kind` (Barra → barra; Cocina y MIXTA →
 * cocina). Mixta se resuelve hacia cocina a conciencia: un plato que no
 * aparece en el tablero de cocina es un cliente esperando de pie, mientras
 * que una bebida colada entre los platos es, como mucho, una molestia.
 *
 * Tres consultas fijas y ninguna dentro de un bucle — el tablero se pide
 * cada pocos segundos desde cada tablet del festival, y una consulta por
 * tarjeta se convertiría en cientos por minuto. El resto es PHP sobre lo
 * que ya está en memoria.
 */
class KitchenBoard
{
    /**
     * Cuánto pasado se mira. Doce horas cubren el turno más largo de un
     * festival sin arrastrar el tablero de anteayer.
     */
    private const HORAS_DE_VENTANA = 12;

    /**
     * Cuánto se queda una comanda ya lista antes de caerse del tablero.
     *
     * Lista es TERMINAL: no hay «entregada» que darle después, así que sin
     * este corte la columna crecería toda la noche hasta ser inservible.
     * Veinte minutos es el margen para que quien la marcó por error pueda
     * volver atrás y para que el cliente que tardó en recogerla la encuentre.
     * Lo pendiente y lo que está en proceso NO caduca nunca: un pedido
     * olvidado tiene que seguir gritando en la pantalla hasta que alguien
     * lo despache.
     */
    private const MINUTOS_EN_LISTA = 20;

    /**
     * @param  array<int, int>  $unitIds
     * @return Collection<int, KitchenTicketView>
     */
    public function forUnits(array $unitIds, ?DispatchArea $area = null): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        // El array (y no una sola unidad) desde el primer día: hoy una tablet
        // vigila un puesto, pero la cocina compartida que despacha para tres
        // barras ya está pedida, y entonces solo cambia quién llena la lista.
        $ordenes = Order::query()
            ->whereIn('operating_unit_id', $unitIds)
            ->where('status', OrderStatus::Paid)
            ->where('paid_at', '>=', now()->subHours(self::HORAS_DE_VENTANA))
            ->with(['lines', 'operatingUnit'])
            ->orderBy('paid_at')
            // Desempate estable: dos ventas del mismo segundo tienen que
            // salir siempre en el mismo orden o el ETag baila sin motivo.
            ->orderBy('id')
            ->get();

        if ($ordenes->isEmpty()) {
            return collect();
        }

        $ids = $ordenes->modelKeys();

        $comandas = KitchenTicket::query()
            ->whereIn('order_id', $ids)
            ->get()
            ->keyBy(fn (KitchenTicket $comanda): string => $this->clave($comanda->order_id, $comanda->area));

        // Agregado en la base: traerse los reembolsos uno a uno solo sirve
        // para sumarlos aquí. Lo devuelto se pinta como aviso en la tarjeta
        // —«de esto ya se devolvió dinero»— y no toca el estado: RefundOrder
        // no escribe en orders.status, la venta sigue siendo lo que fue.
        $devuelto = Refund::query()
            ->whereIn('order_id', $ids)
            ->selectRaw('order_id, sum(amount_cents) as devuelto')
            ->groupBy('order_id')
            ->pluck('devuelto', 'order_id');

        $corte = now()->subMinutes(self::MINUTOS_EN_LISTA);
        $tablero = collect();

        foreach ($ordenes as $orden) {
            // El where de arriba ya excluye las ventas sin hora de cobro;
            // esto es para el analizador, que lee el tipo nullable de la
            // columna y no la cláusula.
            $paidAt = $orden->paid_at;

            if ($paidAt === null) {
                continue;
            }

            $porArea = $this->lineasPorArea($orden);

            // Los estados se resuelven ANTES de filtrar, y de TODAS las áreas
            // de la venta: la tarjeta de cocina necesita saber cómo va la
            // barra para que nadie entregue media orden, y eso vale también
            // cuando la otra mitad ya se cayó del tablero por llevar rato lista.
            $estados = [];
            $filas = [];

            foreach (array_keys($porArea) as $valor) {
                $comanda = $comandas->get($this->clave($orden->id, DispatchArea::from($valor)));
                $filas[$valor] = $comanda;
                // Sin fila y con fila en pendiente son EL MISMO estado: la
                // vuelta atrás no borra nada (la comanda no se borra nunca),
                // así que el tablero tiene que leer las dos igual.
                $estados[$valor] = $comanda === null ? KitchenTicketStatus::Pending : $comanda->status;
            }

            foreach ($porArea as $valor => $lineas) {
                $areaDeLaTarjeta = DispatchArea::from($valor);

                if ($area !== null && $areaDeLaTarjeta !== $area) {
                    continue;
                }

                $comanda = $filas[$valor];
                $estado = $estados[$valor];

                // Una venta DEVUELTA ENTERA que nadie llegó a tocar no es una
                // comanda pendiente: es una venta que se deshizo antes de
                // llegar a la cocina, y nadie va a cocinarla nunca.
                //
                // Sin este corte se quedaba pendiente para siempre. RefundOrder
                // no escribe en orders.status a conciencia —la venta sigue
                // siendo lo que fue—, así que seguía entrando en el tablero,
                // sin fila que la cerrara y con un reloj que no paraba: cada
                // noche acababa arrastrando a su comercio al primer puesto del
                // tablero del organizador por un plato que no existe.
                //
                // Se exige que la devolución sea COMPLETA. Un reembolso es un
                // importe, no unas líneas: con uno parcial nadie sabe si lo que
                // volvió fue el refresco o el plato, así que la comanda se
                // queda y decide la cocina, que para eso ve la franja de
                // «devuelta» en su tarjeta.
                //
                // Y se exige que NO tenga fila. Si alguien ya la empezó, está
                // cocinándola ahora mismo: hacerla desaparecer de la pantalla
                // dejaría a esa persona con un plato en la plancha y sin
                // explicación. Esa se queda, con su franja, para que pueda
                // parar y cerrarla.
                if ($comanda === null && $this->devueltaEntera($orden, $devuelto)) {
                    continue;
                }

                // Una comanda lista y vieja se cae. Sin marca de hora se
                // queda: preferimos una tarjeta de más en la pantalla a una
                // que desaparece sin que nadie sepa por qué.
                if ($estado === KitchenTicketStatus::Ready
                    && $comanda !== null
                    && $comanda->ready_at !== null
                    && $comanda->ready_at->lt($corte)) {
                    continue;
                }

                $tablero->push(KitchenTicketView::from(
                    orderId: $orden->id,
                    area: $areaDeLaTarjeta,
                    status: $estado,
                    // Ya renderizado: order_number es nullable (las ventas
                    // anteriores al contador no lo tienen) y pintarlo a pelo
                    // dejaría tarjetas sin número que nadie sabe cantar.
                    numero: $orden->publicNumber(),
                    customerName: $this->nombreDelCliente($orden),
                    paidAt: $paidAt,
                    deviceSoldAt: $orden->device_sold_at,
                    startedAt: $comanda?->started_at,
                    readyAt: $comanda?->ready_at,
                    lines: $this->vistasDeLinea($lineas),
                    itemsCount: $this->unidades($lineas),
                    estadoHermano: $this->estadoHermano($estados, $valor),
                    refundedCents: (int) ($devuelto->get($orden->id) ?? 0),
                ));
            }
        }

        return $tablero->values();
    }

    /**
     * Las líneas de la venta repartidas por área de despacho, en el orden
     * declarado del enum para que dos lecturas seguidas den lo mismo.
     *
     * @return array<string, list<OrderLine>>
     */
    private function lineasPorArea(Order $orden): array
    {
        $porDefecto = $this->areaPorDefecto($orden);
        $sueltas = [];

        foreach ($orden->lines as $linea) {
            $sueltas[($linea->dispatch ?? $porDefecto)->value][] = $linea;
        }

        $ordenadas = [];

        foreach (DispatchArea::cases() as $caso) {
            if (isset($sueltas[$caso->value])) {
                $ordenadas[$caso->value] = $sueltas[$caso->value];
            }
        }

        return $ordenadas;
    }

    /** Dónde cae una línea que no congeló su área. */
    private function areaPorDefecto(Order $orden): DispatchArea
    {
        return $orden->operatingUnit?->kind === OperatingUnitKind::Bar
            ? DispatchArea::Bar
            : DispatchArea::Kitchen;
    }

    /**
     * El estado de la OTRA área de la misma venta, si la venta tiene dos.
     *
     * @param  array<string, KitchenTicketStatus>  $estados
     */
    private function estadoHermano(array $estados, string $valor): ?KitchenTicketStatus
    {
        foreach ($estados as $otro => $estado) {
            if ($otro !== $valor) {
                return $estado;
            }
        }

        return null;
    }

    /**
     * @param  list<OrderLine>  $lineas
     * @return Collection<int, KitchenLineView>
     */
    private function vistasDeLinea(array $lineas): Collection
    {
        return collect($lineas)->map(fn (OrderLine $linea): KitchenLineView => KitchenLineView::from(
            cantidad: (float) $linea->quantity,
            productName: $linea->product_name,
            notes: $linea->notes,
        ))->values();
    }

    /**
     * Cuántas unidades salen por esta área. Se cuenta de las líneas y no de
     * kitchen_tickets.items_count: las líneas están siempre, la fila puede
     * no existir todavía, y las dos tienen que decir lo mismo.
     *
     * @param  list<OrderLine>  $lineas
     */
    private function unidades(array $lineas): int
    {
        return (int) round(collect($lineas)->sum(fn (OrderLine $linea): float => (float) $linea->quantity));
    }

    /**
     * Si de esta venta volvió TODO el dinero.
     *
     * Se compara contra el total cobrado, propina incluida: solo cuenta como
     * deshecha la venta de la que no quedó nada. Devolver el importe de la
     * comida y quedarse la propina deja una venta viva, y su comanda también.
     *
     * @param  Collection<int, mixed>  $devuelto
     */
    private function devueltaEntera(Order $orden, Collection $devuelto): bool
    {
        $total = $orden->total_cents;

        return $total > 0 && (int) ($devuelto->get($orden->id) ?? 0) >= $total;
    }

    private function clave(int $orderId, DispatchArea $area): string
    {
        return $orderId.':'.$area->value;
    }

    /**
     * El nombre va sin docblock en Order y Larastan no lo conoce; se lee por
     * el atributo para no depender de una propiedad mágica sin tipo.
     */
    private function nombreDelCliente(Order $orden): ?string
    {
        $nombre = $orden->getAttribute('customer_name');

        return is_string($nombre) ? $nombre : null;
    }
}
