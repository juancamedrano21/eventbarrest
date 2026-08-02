<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Queries;

use App\Domains\EventManagement\Enums\CommissionBase;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Sales\Enums\OrderStatus;
use App\Domains\Sales\Models\Order;
use App\Domains\Sales\Models\Refund;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Lo que cada comercio vendió en un evento y lo que de eso le toca al
 * organizador.
 *
 * Cada orden lleva CONGELADOS su porcentaje y su base, así que el cálculo se
 * hace orden a orden y luego se suma: dos ventas del mismo comercio pueden
 * haberse cobrado con reglas distintas si el ajuste cambió a mitad del
 * festival, y la cuenta tiene que respetar lo que se pactó en cada momento.
 *
 * Un reembolso reduce la base en la misma proporción en que se devolvió la
 * venta — devolver la mitad devuelve la mitad de la comisión. Cobrarle al
 * comercio por dinero que le devolvió al cliente sería cobro indebido.
 */
class SettlementFigures
{
    /**
     * Las cifras de cada comercio del evento, listas para congelar.
     *
     * @return Collection<int, SettlementRow>
     */
    public function forEvent(Event $event): Collection
    {
        // Una fila por (comercio, base, porcentaje): las combinaciones que de
        // verdad se usaron. Sumarlas todas con una sola base daría un número
        // que no corresponde a ninguna venta.
        $filas = Order::query()
            ->join('operating_units as u', 'u.id', '=', 'orders.operating_unit_id')
            ->join('vendors as v', 'v.id', '=', 'u.vendor_id')
            ->where('u.event_id', $event->id)
            ->where('orders.status', OrderStatus::Paid->value)
            // Subconsulta AGREGADA: una venta con dos reembolsos duplicaría
            // su fila y con ella el bruto.
            ->leftJoinSub(
                Refund::query()->selectRaw('order_id, SUM(amount_cents) as devuelto')->groupBy('order_id'),
                'r',
                'r.order_id',
                '=',
                'orders.id',
            )
            ->selectRaw(
                'u.vendor_id as vendor_id, v.name as vendor_name, '
                ."COALESCE(orders.commission_base, '".CommissionBase::Total->value."') as base_regla, "
                .'COALESCE(orders.commission_bps, 0) as bps, '
                .'COUNT(*) as ordenes, '
                .'COALESCE(SUM(orders.total_cents), 0) as bruto, '
                .'COALESCE(SUM(r.devuelto), 0) as devuelto, '
                // Propina e ITBIS que de verdad se quedaron: lo devuelto se
                // lleva su parte proporcional, igual que en el resumen del
                // negocio. El 1.0 fuerza aritmética decimal, para que SQLite
                // y MySQL digan lo mismo del mismo dinero.
                .'COALESCE(SUM(ROUND(orders.tip_cents * 1.0 '
                .'* (orders.total_cents - COALESCE(r.devuelto, 0)) '
                .'/ NULLIF(orders.total_cents, 0))), 0) as propina, '
                .'COALESCE(SUM(ROUND(orders.itbis_cents * 1.0 '
                .'* (orders.total_cents - COALESCE(r.devuelto, 0)) '
                .'/ NULLIF(orders.total_cents, 0))), 0) as itbis, '
                // La base de comisión de cada orden, ya neta de lo devuelto.
                .'COALESCE(SUM(ROUND('.$this->baseSql().' * 1.0 '
                .'* (orders.total_cents - COALESCE(r.devuelto, 0)) '
                .'/ NULLIF(orders.total_cents, 0))), 0) as base_comision'
            )
            ->groupBy('u.vendor_id', 'v.name', 'orders.commission_base', 'orders.commission_bps')
            ->toBase()
            ->get();

        return $filas
            ->groupBy('vendor_id')
            ->map(function (Collection $delComercio): SettlementRow {
                $primera = $delComercio->first();

                $comision = 0;
                foreach ($delComercio as $fila) {
                    // La división entre 10.000 se hace UNA vez por tramo y
                    // sobre enteros: redondear en cada orden acumularía
                    // céntimos que nadie sabría explicar.
                    $comision += (int) round(((int) $fila->base_comision) * ((int) $fila->bps) / 10000);
                }

                $bruto = (int) $delComercio->sum(fn (stdClass $f): int => (int) $f->bruto);
                $devuelto = (int) $delComercio->sum(fn (stdClass $f): int => (int) $f->devuelto);

                return SettlementRow::make(
                    vendorId: (int) $primera->vendor_id,
                    vendorName: (string) $primera->vendor_name,
                    ordersCount: (int) $delComercio->sum(fn (stdClass $f): int => (int) $f->ordenes),
                    grossCents: $bruto,
                    refundedCents: $devuelto,
                    tipCents: (int) $delComercio->sum(fn (stdClass $f): int => (int) $f->propina),
                    itbisCents: (int) $delComercio->sum(fn (stdClass $f): int => (int) $f->itbis),
                    // Si hubo varias reglas, se guarda la de la última venta:
                    // el detalle por tramo vive en las órdenes, y la ficha
                    // enseña con qué se cerró.
                    commissionBase: CommissionBase::tryFrom((string) $primera->base_regla) ?? CommissionBase::Total,
                    commissionBps: (int) $primera->bps,
                    commissionBaseCents: (int) $delComercio->sum(fn (stdClass $f): int => (int) $f->base_comision),
                    commissionCents: $comision,
                );
            })
            ->sortByDesc(fn (SettlementRow $fila): int => $fila->grossCents)
            ->values();
    }

    /**
     * La base de cada orden según SU regla congelada, resuelta en SQL para no
     * traerse cien mil filas a memoria.
     */
    private function baseSql(): string
    {
        $total = CommissionBase::Total->value;
        $sinPropina = CommissionBase::WithoutTip->value;

        return 'CASE orders.commission_base '
            ."WHEN '{$sinPropina}' THEN (orders.total_cents - orders.tip_cents) "
            ."WHEN '".CommissionBase::NetSale->value."' THEN (orders.total_cents - orders.tip_cents - orders.itbis_cents) "
            // Nulo o 'total': la regla histórica, la que se les aplicó.
            ."WHEN '{$total}' THEN orders.total_cents "
            .'ELSE orders.total_cents END';
    }
}
