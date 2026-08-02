<?php

declare(strict_types=1);

namespace App\Domains\Sales\Queries;

use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Refund;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * De todo lo que pasó por la caja, cuánto es realmente del negocio.
 *
 * La propina legal del 10 % viaja SUMADA dentro de `orders.total_cents`, así
 * que sumar esa columna —lo que hacen todos los reportes— le atribuye al bar
 * dinero que por ley es del personal. Aquí se separa: lo que se cobró, lo que
 * se devolvió, lo que es propina y lo que queda como venta.
 *
 * Los reembolsos no dicen qué parte era propina, porque `refunds` guarda un
 * importe plano. Se reparte en la misma proporción en que se devolvió la
 * orden: devolver la mitad de una venta devuelve la mitad de su propina. Es
 * la única lectura posible sin inventar un desglose que nadie registró, y
 * mantiene la identidad ventas + propina + devuelto = cobrado.
 *
 * El corte de fechas es por `paid_at` y arrastra TODOS los reembolsos de esas
 * órdenes, incluso los de días posteriores: la pregunta aquí es «de lo que
 * vendí en este período, cuánto me quedé». Cuando la pregunta sea «cuánto
 * dinero salió hoy de la gaveta», la respuesta es {@see NetSales}, que corta
 * por el día de la devolución para cuadrar con el arqueo.
 */
class SalesSummary
{
    /** @param  int|null  $unitId  Acotar a una sucursal o puesto; null = toda la cuenta. */
    public function forRange(?string $desde = null, ?string $hasta = null, ?int $unitId = null): SalesFigures
    {
        /** @var stdClass|null $fila */
        $fila = $this->base($desde, $hasta, $unitId)->first();

        return SalesFigures::from($fila);
    }

    /**
     * La misma cuenta, día a día, para la gráfica. Agrupa por el día LOCAL:
     * `paid_at` vive en UTC y aquí se corre el offset —fijo en RD, sin
     * horario de verano— antes de quedarse con la fecha.
     *
     * @return Collection<string, SalesFigures>
     */
    public function byDay(string $desde, ?int $unitId = null): Collection
    {
        $tz = (string) config('app.business_timezone');

        $diaLocal = DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("DATE(orders.paid_at, '%+d minutes')", now($tz)->utcOffset())
            : sprintf("DATE(CONVERT_TZ(orders.paid_at, '+00:00', '%s'))", now($tz)->format('P'));

        return $this->base($desde, null, $unitId, "{$diaLocal} as dia")
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->mapWithKeys(fn (stdClass $fila): array => [
                (string) $fila->dia => SalesFigures::from($fila),
            ]);
    }

    /**
     * La misma cuenta, sucursal por sucursal.
     *
     * @return Collection<int, SalesFigures>
     */
    public function byUnit(?string $desde = null, ?string $hasta = null): Collection
    {
        return $this->base($desde, $hasta, null, 'u.name as nombre')
            ->join('operating_units as u', 'u.id', '=', 'orders.operating_unit_id')
            ->groupBy('u.id', 'u.name')
            ->get()
            ->map(fn (stdClass $fila): SalesFigures => SalesFigures::from($fila, (string) $fila->nombre))
            ->sortByDesc('ventas')
            ->values();
    }

    /**
     * El tronco común: órdenes cobradas del rango con sus reembolsos ya
     * agregados y las cuatro expresiones de dinero. Lo único que cambia
     * entre las tres preguntas es por qué se agrupa.
     *
     * @return Builder
     */
    private function base(?string $desde, ?string $hasta, ?int $unitId, ?string $extra = null)
    {
        return Order::query()
            ->where('orders.status', OrderStatus::Paid->value)
            ->when($desde !== null, fn ($q) => $q->where('orders.paid_at', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('orders.paid_at', '<', $hasta))
            ->when($unitId !== null, fn ($q) => $q->where('orders.operating_unit_id', $unitId))
            // Subconsulta AGREGADA, no join directo: una venta con dos
            // reembolsos duplicaría su fila y con ella el bruto.
            ->leftJoinSub(
                Refund::query()->selectRaw('order_id, SUM(amount_cents) as devuelto')->groupBy('order_id'),
                'r',
                'r.order_id',
                '=',
                'orders.id',
            )
            ->selectRaw(
                ($extra === null ? '' : $extra.', ')
                .'COUNT(*) as transacciones, '
                .'COALESCE(SUM(orders.total_cents), 0) as cobrado, '
                .'COALESCE(SUM(r.devuelto), 0) as devuelto, '
                // El 1.0 fuerza aritmética decimal: sin él SQLite trunca
                // donde MySQL redondea, y los dos motores dirían cosas
                // distintas sobre el mismo dinero.
                .'COALESCE(SUM(ROUND(orders.tip_cents * 1.0 '
                .'* (orders.total_cents - COALESCE(r.devuelto, 0)) '
                .'/ NULLIF(orders.total_cents, 0))), 0) as propina'
            )
            ->toBase();
    }
}
