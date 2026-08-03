<?php

declare(strict_types=1);

namespace App\Domains\Kitchen\Exceptions;

use App\Domains\Catalog\Enums\DispatchArea;
use App\Domains\Kitchen\Enums\KitchenTicketStatus;
use RuntimeException;

/**
 * Errores operables del tablero de cocina. Como en Sales, cada uno lleva un
 * código estable machine-readable: la tablet decide por código —repintar el
 * tablero, avisar al usuario o rendirse— y nunca parseando el mensaje.
 *
 * Aquí el httpStatus sí varía, y por un motivo concreto: dos tablets miran
 * el mismo puesto y se pisan a diario. Perder la carrera no es un error de
 * quien tocó (422, «has hecho algo mal»), es un 409: el mundo cambió
 * debajo, refresca y sigue. La tablet distingue justo eso.
 */
class KitchenException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'kitchen_error',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    /**
     * Control optimista: la comanda ya no estaba donde la tablet creía.
     * Devolvemos el estado vigente para que repinte sin otra ida y vuelta.
     */
    public static function estadoCambiado(KitchenTicketStatus $vigente): self
    {
        return new self(
            "Otra tablet movió esta comanda: ahora está «{$vigente->getLabel()}».",
            'kitchen_status_changed',
            409,
        );
    }

    public static function transicionImposible(KitchenTicketStatus $desde, KitchenTicketStatus $hasta): self
    {
        return new self(
            "Una comanda «{$desde->getLabel()}» no pasa a «{$hasta->getLabel()}».",
            'kitchen_transition_impossible',
        );
    }

    /**
     * El tablero se alimenta de ventas cobradas. Una orden abierta todavía
     * se está tecleando y una anulada no ocurrió: ni una ni otra se cocinan.
     */
    public static function ordenNoCobrada(): self
    {
        return new self('Solo se cocina una venta cobrada.', 'kitchen_order_not_paid');
    }

    /**
     * Último filtro de negocio sobre el de la base: VendorScope falla
     * ABIERTO, así que sin este chequeo una tablet enrolada en un puesto
     * podría mover la comanda de otro con solo cambiar el id de la URL.
     *
     * Responde 404 y no 403 a propósito: lo que no es tuyo no existe, y así
     * probar ids a mano tampoco sirve para averiguar qué vende el vecino.
     */
    public static function ordenDeOtroPuesto(): self
    {
        return new self('Esa comanda no existe en este puesto.', 'kitchen_wrong_unit', 404);
    }

    public static function areaSinLineas(DispatchArea $area): self
    {
        return new self(
            "Esta venta no tiene nada que despachar por {$area->getLabel()}.",
            'kitchen_area_without_lines',
        );
    }

    /** La identidad de la comanda —qué, dónde y cuánto— nace con ella. */
    public static function identidadInmutable(): self
    {
        return new self(
            'Una comanda no cambia de orden, de área, de puesto ni de cantidad: eso sería otra comanda.',
            'kitchen_identity_immutable',
        );
    }

    /** El estado solo se mueve por la puerta que valida y sella la hora. */
    public static function estadoSoloPorTransicion(): self
    {
        return new self(
            'El estado de una comanda solo se escribe con una transición.',
            'kitchen_status_needs_transition',
        );
    }

    public static function comandaNoSeBorra(): self
    {
        return new self(
            'Una comanda no se borra: es el rastro de quién cocinó qué y cuándo.',
            'kitchen_ticket_not_deletable',
        );
    }
}
