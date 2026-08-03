<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * En qué punto va una comanda. Tres estados y solo tres: lo que se ve desde
 * la tablet de un puesto. No existe «Entregada» —quien la entrega es quien
 * la marcó Lista, y pedir un segundo toque solo consigue que nadie lo dé—
 * ni «Cancelada»: una venta que no debió existir se anula o se reembolsa en
 * Sales, y la comanda desaparece del tablero con ella.
 *
 * PENDIENTE JAMÁS SE PERSISTE. No es un valor que viva en la columna: es la
 * AUSENCIA de fila en kitchen_tickets. Una venta sincronizada desde el POS
 * aparece pendiente en el tablero por el mero hecho de existir, porque el
 * tablero se lee como `orders LEFT JOIN kitchen_tickets` y la ausencia del
 * lado derecho ya significa «nadie la ha tocado».
 *
 * Eso no es una economía de filas, es lo que hace que el sistema no pueda
 * perder una comanda: no hay observer que crear la fila, ni job que pueda
 * fallar en la cola, ni backfill que haya que correr al desplegar, ni
 * comando reconciliador que alguien tenga que acordarse de agendar. La
 * única forma de que una venta no salga en cocina es que no exista la venta.
 *
 * Lista es TERMINAL en el sentido de que no hay nada después: next() se
 * queda sin siguiente. Pero sí se puede volver atrás — los toques
 * equivocados en una pantalla grasienta y con prisa existen, y un tablero
 * que no perdona obliga al personal a inventarse trucos peores.
 */
enum KitchenTicketStatus: string implements HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Ready = 'ready';

    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InProgress => 'En proceso',
            self::Ready => 'Lista',
        };
    }

    /** Sigue debiendo comida: es lo que cuenta el badge del tablero. */
    public function isOpen(): bool
    {
        return $this !== self::Ready;
    }

    /** El estado al que lleva el toque normal de la tarjeta. */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::InProgress,
            self::InProgress => self::Ready,
            self::Ready => null,
        };
    }

    /**
     * La matriz completa, avances y retrocesos.
     *
     * Pendiente → Lista de un salto es legítimo y frecuente: una cerveza se
     * sirve antes de que a nadie le dé tiempo de marcarla en proceso.
     *
     * Los retrocesos son de UN paso, porque un toque equivocado es de un
     * paso. Lista → Pendiente no existe: eso no es corregir un dedazo, es
     * rehacer el plato, y entonces se dan los dos toques a conciencia.
     */
    public function canTransitionTo(self $destino): bool
    {
        return match ($this) {
            self::Pending => $destino === self::InProgress || $destino === self::Ready,
            self::InProgress => $destino === self::Ready || $destino === self::Pending,
            self::Ready => $destino === self::InProgress,
        };
    }
}
