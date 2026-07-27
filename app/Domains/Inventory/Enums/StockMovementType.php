<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cada tipo fija el signo de su cantidad: el libro mayor no acepta una
 * "compra negativa" ni una "merma positiva". El stock actual es siempre la
 * suma de los movimientos — nunca se edita a mano.
 */
enum StockMovementType: string implements HasColor, HasLabel
{
    case Purchase = 'purchase';
    case SaleConsumption = 'sale_consumption';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Waste = 'waste';
    case Adjustment = 'adjustment';
    case EventAllocation = 'event_allocation';
    case EventReturn = 'event_return';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Purchase => 'Compra',
            self::SaleConsumption => 'Consumo por venta',
            self::TransferIn => 'Transferencia (entrada)',
            self::TransferOut => 'Transferencia (salida)',
            self::Waste => 'Merma',
            self::Adjustment => 'Ajuste',
            self::EventAllocation => 'Asignación a evento',
            self::EventReturn => 'Devolución de evento',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Purchase, self::TransferIn, self::EventAllocation => 'success',
            self::SaleConsumption, self::TransferOut, self::EventReturn => 'info',
            self::Waste => 'danger',
            self::Adjustment => 'warning',
        };
    }

    /** 1 = solo entradas, -1 = solo salidas, 0 = ambos signos (ajuste). */
    public function direction(): int
    {
        return match ($this) {
            self::Purchase, self::TransferIn, self::EventAllocation => 1,
            self::SaleConsumption, self::TransferOut, self::Waste, self::EventReturn => -1,
            self::Adjustment => 0,
        };
    }
}
