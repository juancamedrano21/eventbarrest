<?php

declare(strict_types=1);

namespace App\Domains\Sales\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Cómo se relaciona el precio de carta con el ITBIS (18 %).
 *
 * En RD conviven las dos modalidades: los bares suelen vender con el
 * impuesto YA INCLUIDO en el precio (el desglose se calcula hacia adentro,
 * ×18/118, y el total no crece), mientras muchos restaurantes lo cobran
 * POR FUERA (el precio es la base, el impuesto se suma al total).
 *
 * La regla es del negocio, no del producto: el producto solo declara si
 * está gravado o exento.
 */
enum ItbisMode: string implements HasLabel
{
    case Included = 'included';
    case Added = 'added';

    public function getLabel(): string
    {
        return match ($this) {
            self::Included => 'Incluido en el precio',
            self::Added => 'Se suma al precio',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Included => 'El precio de carta ya lleva el 18 %: el desglose es informativo y el total no crece.',
            self::Added => 'El precio de carta es la base: el 18 % se suma al cobrar.',
        };
    }

    /**
     * El ITBIS de una línea gravada, en centavos.
     *
     * Incluido: se extrae del propio importe (×18/118).
     * Por fuera: se calcula sobre él (×18 %).
     */
    public function itbisOf(int $lineTotalCents): int
    {
        return match ($this) {
            self::Included => (int) round($lineTotalCents * 18 / 118),
            self::Added => (int) round($lineTotalCents * 0.18),
        };
    }

    /**
     * La base sobre la que se calcula la propina legal: el importe sin
     * impuesto, venga por dentro o por fuera.
     */
    public function baseWithoutItbis(int $subtotalCents, int $itbisCents): int
    {
        return match ($this) {
            self::Included => $subtotalCents - $itbisCents,
            self::Added => $subtotalCents,
        };
    }

    /** Lo que el cliente paga, sin contar la propina. */
    public function totalOf(int $subtotalCents, int $itbisCents): int
    {
        return match ($this) {
            self::Included => $subtotalCents,
            self::Added => $subtotalCents + $itbisCents,
        };
    }
}
