<?php

declare(strict_types=1);

namespace App\Domains\EventManagement\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Sobre qué dinero cobra su comisión el organizador.
 *
 * No es un detalle contable: sobre una venta de RD$1,000 con ITBIS incluido
 * y propina, un 10 % pactado son RD$84.75 o RD$108.48 según lo que se elija.
 * La diferencia es el 28 %.
 *
 * El valor se CONGELA en cada orden junto al porcentaje pactado. Cambiar el
 * ajuste rige de ahí en adelante y jamás reescribe lo ya cobrado — igual que
 * el precio y el ITBIS de cada línea.
 */
enum CommissionBase: string implements HasLabel
{
    /** Todo lo que pasó por la caja, propina e impuesto incluidos. */
    case Total = 'total';

    /** Lo cobrado menos la propina del personal. */
    case WithoutTip = 'without_tip';

    /** Solo la venta del comercio: sin propina y sin ITBIS. */
    case NetSale = 'net_sale';

    public function getLabel(): string
    {
        return match ($this) {
            self::Total => 'Todo lo cobrado',
            self::WithoutTip => 'Sin la propina',
            self::NetSale => 'Solo la venta del comercio',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Total => 'Incluye el ITBIS y la propina legal. Es el máximo ingreso para ti, '
                .'pero el comercio paga comisión sobre la propina de sus meseros y sobre el '
                .'impuesto que le debe a la DGII.',
            self::WithoutTip => 'La propina es del personal y queda fuera. Sigue cobrándose '
                .'sobre el ITBIS.',
            self::NetSale => 'Ni el impuesto ni la propina son ingreso del comercio, así que no '
                .'pagan comisión. Es lo más fácil de defender cuando un comercio revisa la cuenta.',
        };
    }

    /**
     * La base de UNA orden, con las cifras que ella misma congeló.
     *
     * `total − propina − ITBIS` funciona igual con el impuesto incluido en el
     * precio o sumado al cobrar: en el primer caso el ITBIS vive dentro del
     * subtotal y en el segundo va aparte, pero en ambos está dentro del total.
     */
    public function baseOf(int $totalCents, int $tipCents, int $itbisCents): int
    {
        return match ($this) {
            self::Total => $totalCents,
            self::WithoutTip => $totalCents - $tipCents,
            self::NetSale => $totalCents - $tipCents - $itbisCents,
        };
    }

    /** La expresión SQL equivalente, para calcular sobre muchas órdenes a la vez. */
    public function sqlBase(string $prefix = 'orders.'): string
    {
        return match ($this) {
            self::Total => "{$prefix}total_cents",
            self::WithoutTip => "({$prefix}total_cents - {$prefix}tip_cents)",
            self::NetSale => "({$prefix}total_cents - {$prefix}tip_cents - {$prefix}itbis_cents)",
        };
    }
}
