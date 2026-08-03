<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Una tarjeta del tablero: lo que un puesto debe despachar por un área.
 *
 * Existe aunque no haya fila en kitchen_tickets. Cuando nadie ha tocado la
 * venta, esto se construye con status Pendiente a partir de la orden sola:
 * es la mitad de arriba del LEFT JOIN, y por eso ninguna venta puede
 * perderse por el camino.
 *
 * AQUÍ NO SE CALCULAN SEGUNDOS TRANSCURRIDOS, y no es un descuido. El
 * endpoint del tablero calcula su ETag sobre este payload para responder
 * 304 mientras nada cambie; un «hace 4 segundos» cambiaría el hash cada
 * segundo y el 304 no ocurriría jamás — la tablet se descargaría el tablero
 * entero cada dos segundos toda la noche. Se devuelven MARCAS de tiempo y
 * el cronómetro lo pinta el cliente, que además tiene el reloj delante.
 */
final readonly class KitchenTicketView
{
    /**
     * @param  Collection<int, KitchenLineView>  $lines
     */
    private function __construct(
        public int $orderId,
        public DispatchArea $area,
        public KitchenTicketStatus $status,
        public string $numero,
        public ?string $customerName,
        public CarbonInterface $paidAt,
        public ?CarbonInterface $deviceSoldAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $readyAt,
        public Collection $lines,
        public int $itemsCount,
        public ?KitchenTicketStatus $estadoHermano,
        public int $refundedCents,
    ) {}

    /**
     * @param  Collection<int, KitchenLineView>  $lines
     */
    public static function from(
        int $orderId,
        DispatchArea $area,
        KitchenTicketStatus $status,
        string $numero,
        ?string $customerName,
        CarbonInterface $paidAt,
        ?CarbonInterface $deviceSoldAt,
        ?CarbonInterface $startedAt,
        ?CarbonInterface $readyAt,
        Collection $lines,
        int $itemsCount,
        ?KitchenTicketStatus $estadoHermano,
        int $refundedCents,
    ): self {
        return new self(
            orderId: $orderId,
            area: $area,
            status: $status,
            numero: $numero,
            customerName: $customerName,
            paidAt: $paidAt,
            deviceSoldAt: $deviceSoldAt,
            startedAt: $startedAt,
            readyAt: $readyAt,
            lines: $lines,
            itemsCount: $itemsCount,
            estadoHermano: $estadoHermano,
            refundedCents: $refundedCents,
        );
    }

    /**
     * La venta se cobró en la caja hace rato y esto acaba de aparecer aquí.
     *
     * Pasa cuando el POS estaba sin cobertura: el cliente lleva esperando
     * desde su reloj, no desde el nuestro, y la tarjeta tiene que decirlo
     * para que nadie la trate como recién llegada. Dos minutos de margen
     * porque por debajo de eso es sincronización normal, no un olvido.
     */
    public function llegoTarde(): bool
    {
        return $this->deviceSoldAt !== null
            && $this->deviceSoldAt->diffInSeconds($this->paidAt, absolute: true) > 120;
    }

    /**
     * Desde cuándo espera el cliente. La hora del cajero manda cuando la
     * hay: es la de verdad. La del servidor es cuándo nos enteramos.
     */
    public function esperaDesde(): CarbonInterface
    {
        return $this->deviceSoldAt ?? $this->paidAt;
    }
}
