<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

use App\Domains\Catalog\Enums\DispatchArea;

/**
 * Una fila del desglose: un comercio, en un puesto, por un área.
 *
 * EL ÁREA ENTRA EN LA CLAVE Y NO ES UN DETALLE. Servir una cerveza y hacer
 * un taco no son el mismo oficio: la barra despacha en veinte segundos y la
 * cocina en ocho minutos, y una fila que los promedie no describe a nadie —
 * ni sirve para comparar dos puestos, porque el que vende más bebida saldrá
 * siempre «más rápido» sin que su gente haya hecho nada mejor.
 *
 * Las comandas abiertas viajan en la MISMA fila que los tiempos, pegadas a
 * ellos, porque separarlas es lo que permite el engaño: un puesto que dejó
 * diez comandas colgadas y solo cerró las tres fáciles enseñaría los mejores
 * tiempos del evento. Quien lea `espera` tiene `openCount` al lado.
 */
final readonly class TimingBreakdown
{
    private function __construct(
        public int $vendorId,
        public string $vendorName,
        public int $unitId,
        public string $unitName,
        public DispatchArea $area,
        public TimingSummary $espera,
        public TimingSummary $cola,
        public TimingSummary $preparando,
        public TimingSummary $syncDelay,
        public int $readyCount,
        public int $openCount,
        public ?int $oldestOpenSeconds,
    ) {}

    public static function from(
        int $vendorId,
        string $vendorName,
        int $unitId,
        string $unitName,
        DispatchArea $area,
        TimingSummary $espera,
        TimingSummary $cola,
        TimingSummary $preparando,
        TimingSummary $syncDelay,
        int $readyCount,
        int $openCount,
        ?int $oldestOpenSeconds,
    ): self {
        return new self(
            vendorId: $vendorId,
            vendorName: $vendorName,
            unitId: $unitId,
            unitName: $unitName,
            area: $area,
            espera: $espera,
            cola: $cola,
            preparando: $preparando,
            syncDelay: $syncDelay,
            readyCount: $readyCount,
            openCount: $openCount,
            oldestOpenSeconds: $oldestOpenSeconds,
        );
    }

    /**
     * La clave con la que se juntan lo terminado y lo abierto del mismo
     * sitio. Las dos consultas del informe son independientes y esto es lo
     * único que las une.
     */
    public static function claveDe(int $vendorId, int $unitId, DispatchArea $area): string
    {
        return $vendorId.':'.$unitId.':'.$area->value;
    }

    /**
     * Hay algo que mirar aquí. Una fila sin comandas terminadas ni abiertas
     * no es una fila lenta, es un puesto que no vendió.
     */
    public function hasActivity(): bool
    {
        return $this->readyCount > 0 || $this->openCount > 0;
    }

    /**
     * Cuántas de las comandas de este sitio siguen sin salir, en tanto por
     * ciento. Es la cifra que desmonta unos tiempos demasiado bonitos.
     */
    public function openPercent(): float
    {
        $total = $this->readyCount + $this->openCount;

        return $total === 0 ? 0.0 : round($this->openCount * 100 / $total, 1);
    }
}
