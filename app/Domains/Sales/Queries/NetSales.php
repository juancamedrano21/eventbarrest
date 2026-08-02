<?php

declare(strict_types=1);

namespace App\Domains\Sales\Queries;

use App\Domains\Sales\Models\Refund;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo devuelto, para que ningún reporte cuente como venta el dinero que
 * volvió al cliente — ni le cobre comisión al comercio por él.
 *
 * El reembolso resta el día EN QUE SE DEVOLVIÓ, no el día de la venta: es
 * cuando el dinero salió de la gaveta, y así los reportes cuadran con el
 * arqueo de caja, que es la verdad física del turno.
 */
class NetSales
{
    /**
     * Base de reembolsos ya acotada por el tenant activo (scope global).
     *
     * @return Builder<Refund>
     */
    public function query(): Builder
    {
        return Refund::query();
    }

    /** Lo devuelto en un rango, por el día en que se devolvió. */
    public function refundedBetween(?string $desde = null, ?string $hasta = null, ?int $vendorId = null): int
    {
        return (int) $this->query()
            ->when($desde !== null, fn ($q) => $q->where('refunds.created_at', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('refunds.created_at', '<', $hasta))
            ->when($vendorId !== null, fn ($q) => $q->where('refunds.vendor_id', $vendorId))
            ->sum('amount_cents');
    }
}
