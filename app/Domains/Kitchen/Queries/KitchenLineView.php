<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Queries;

/**
 * Una línea de la comanda tal y como hay que leerla para prepararla.
 *
 * No lleva precios ni impuestos a propósito: quien cocina no negocia el
 * dinero, y meter centavos en esta pantalla solo consigue que se enseñe
 * la cuenta de un cliente a quien no le toca verla.
 *
 * `notes` es lo primero que hay que mirar de la línea («sin cebolla»),
 * y viene de la venta, congelada con ella.
 */
final readonly class KitchenLineView
{
    private function __construct(
        public float $cantidad,
        public string $productName,
        public ?string $notes,
    ) {}

    public static function from(
        float $cantidad,
        string $productName,
        ?string $notes,
    ): self {
        return new self(
            cantidad: $cantidad,
            productName: $productName,
            // Una nota en blanco no es una nota: la tarjeta no debe pintar
            // un renglón vacío bajo el plato como si dijera algo.
            notes: ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
        );
    }
}
