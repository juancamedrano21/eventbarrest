<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Queries;

/**
 * Una línea del cuadre: un insumo en un puesto.
 *
 * `missing` es la pregunta del cierre: lo que entró menos lo que salió por
 * una razón conocida. Positivo, falta mercancía; negativo, apareció de más
 * —normalmente porque alguien devolvió sin haber recibido, o porque un
 * conteo se registró como devolución.
 */
final readonly class EventStockLine
{
    private function __construct(
        public int $outletId,
        public string $outletName,
        public string $vendorName,
        public int $itemId,
        public string $itemName,
        public string $baseUnit,
        public float $allocated,
        public float $purchased,
        public float $sold,
        public float $wasted,
        public float $returned,
        public float $adjusted,
        public float $transferredIn,
        public float $transferredOut,
        public float $missing,
    ) {}

    public static function from(
        int $outletId,
        string $outletName,
        string $vendorName,
        int $itemId,
        string $itemName,
        string $baseUnit,
        float $allocated,
        float $purchased,
        float $sold,
        float $wasted,
        float $returned,
        float $adjusted,
        float $transferredIn,
        float $transferredOut,
    ): self {
        // Todo lo que entró al puesto, menos todo lo que salió con una razón.
        // El ajuste va sumado con su signo: un conteo que corrigió a la baja
        // ya explica parte del hueco y no debe contarse dos veces.
        $entradas = $allocated + $purchased + $transferredIn + $adjusted;
        $salidas = $sold + $wasted + $returned + $transferredOut;

        return new self(
            outletId: $outletId,
            outletName: $outletName,
            vendorName: $vendorName,
            itemId: $itemId,
            itemName: $itemName,
            baseUnit: $baseUnit,
            allocated: $allocated,
            purchased: $purchased,
            sold: $sold,
            wasted: $wasted,
            returned: $returned,
            adjusted: $adjusted,
            transferredIn: $transferredIn,
            transferredOut: $transferredOut,
            missing: round($entradas - $salidas, 3),
        );
    }

    /** Si nadie entregó ni compró nada, no hay cuadre que hacer. */
    public function hasMovement(): bool
    {
        return $this->allocated > 0 || $this->purchased > 0 || $this->transferredIn > 0;
    }

    /** Cuánto de lo entregado no aparece, en tanto por ciento. */
    public function missingPercent(): float
    {
        $entregado = $this->allocated + $this->purchased + $this->transferredIn;

        return $entregado <= 0 ? 0.0 : round($this->missing * 100 / $entregado, 1);
    }
}
