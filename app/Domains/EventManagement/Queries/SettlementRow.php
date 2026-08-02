<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Queries;

use App\Domains\EventManagement\Enums\CommissionBase;

/**
 * La cuenta de UN comercio en un evento: lo que vendió, lo que devolvió y lo
 * que de eso le toca al organizador.
 *
 * Se cumple siempre que vendido − devuelto − comisión = lo que le queda. Por
 * eso `netCents` se calcula aquí una sola vez y no en cada pantalla: es la
 * resta que hay que hacer bien.
 */
final readonly class SettlementRow
{
    public function __construct(
        public int $vendorId,
        public string $vendorName,
        public int $ordersCount,
        public int $grossCents,
        public int $refundedCents,
        public int $tipCents,
        public int $itbisCents,
        public CommissionBase $commissionBase,
        public int $commissionBps,
        public int $commissionBaseCents,
        public int $commissionCents,
        public int $netCents,
    ) {}

    public static function make(
        int $vendorId,
        string $vendorName,
        int $ordersCount,
        int $grossCents,
        int $refundedCents,
        int $tipCents,
        int $itbisCents,
        CommissionBase $commissionBase,
        int $commissionBps,
        int $commissionBaseCents,
        int $commissionCents,
    ): self {
        return new self(
            vendorId: $vendorId,
            vendorName: $vendorName,
            ordersCount: $ordersCount,
            grossCents: $grossCents,
            refundedCents: $refundedCents,
            tipCents: $tipCents,
            itbisCents: $itbisCents,
            commissionBase: $commissionBase,
            commissionBps: $commissionBps,
            commissionBaseCents: $commissionBaseCents,
            commissionCents: $commissionCents,
            // Lo que el comercio se queda: lo que cobró, menos lo que
            // devolvió, menos la comisión del organizador.
            netCents: $grossCents - $refundedCents - $commissionCents,
        );
    }

    /** El porcentaje pactado, para enseñarlo sin que nadie divida a mano. */
    public function commissionPercent(): float
    {
        return round($this->commissionBps / 100, 2);
    }
}
