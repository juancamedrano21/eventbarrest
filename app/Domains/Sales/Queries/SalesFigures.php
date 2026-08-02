<?php

declare(strict_types=1);

namespace App\Domains\Sales\Queries;

use stdClass;

/**
 * Lo que pasó por la caja, ya repartido: lo cobrado se descompone en venta
 * del negocio, propina del personal y dinero devuelto.
 *
 * Se cumple siempre que ventas + propina + devuelto = cobrado. Por eso
 * `ventas` se calcula una sola vez, aquí, y no en cada pantalla que lo
 * necesite: es la resta que hay que hacer bien.
 */
final readonly class SalesFigures
{
    private function __construct(
        public int $cobrado,
        public int $devuelto,
        public int $propina,
        public int $ventas,
        public int $transacciones,
        public string $nombre,
    ) {}

    /** Una fila agregada de {@see SalesSummary}. */
    public static function from(?stdClass $fila, string $nombre = ''): self
    {
        $cobrado = (int) ($fila->cobrado ?? 0);
        $devuelto = (int) ($fila->devuelto ?? 0);
        $propina = (int) ($fila->propina ?? 0);

        return new self(
            cobrado: $cobrado,
            devuelto: $devuelto,
            propina: $propina,
            ventas: $cobrado - $devuelto - $propina,
            transacciones: (int) ($fila->transacciones ?? 0),
            nombre: $nombre,
        );
    }

    /** Un período sin una sola venta, o sin permiso para verlas. */
    public static function empty(): self
    {
        return self::from(null);
    }
}
