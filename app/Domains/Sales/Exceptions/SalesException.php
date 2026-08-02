<?php

declare(strict_types=1);

namespace App\Domains\Sales\Exceptions;

use RuntimeException;

/**
 * Errores operables del dominio de ventas. Cada uno lleva un código estable
 * machine-readable: la PWA offline clasifica por código (permanente vs
 * reintentable), nunca parseando mensajes en español.
 */
class SalesException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'sales_error')
    {
        parent::__construct($message);
    }

    public static function sessionAlreadyOpen(string $unit): self
    {
        return new self("[{$unit}] ya tiene una caja abierta: ciérrala antes de abrir otra.", 'session_already_open');
    }

    public static function sessionNotOpen(): self
    {
        return new self('La caja no está abierta: sin sesión de caja no hay ventas ni cierres.', 'session_not_open');
    }

    public static function orderNeedsLines(): self
    {
        return new self('Una orden necesita al menos una línea.', 'order_needs_lines');
    }

    public static function orderNotOpen(): self
    {
        return new self('Solo una orden abierta se puede cobrar o anular.', 'order_not_open');
    }

    public static function productNotSellable(string $product): self
    {
        return new self("[{$product}] no está activo: no se puede vender.", 'product_not_sellable');
    }

    public static function unitOutsideTenant(): self
    {
        return new self('La unidad indicada no pertenece a esta cuenta.', 'unit_outside_tenant');
    }

    public static function lineOutsideOrderVendor(): self
    {
        return new self('Cada línea vende un producto del comercio de la unidad: no se cruzan comercios.', 'line_outside_vendor');
    }

    public static function unknownItbisMode(string $modo, string $origen): self
    {
        return new self(
            "La modalidad de ITBIS {$origen} tiene un valor desconocido: «{$modo}». ".
            'Corrígela en la configuración antes de seguir vendiendo.',
            'unknown_itbis_mode',
        );
    }

    public static function paidOrdersAreHistory(): self
    {
        return new self('Una orden cobrada es historia: se anula con su acción, nunca se edita.', 'history_is_immutable');
    }

    public static function paymentBelowTotal(): self
    {
        return new self('El cobro no cubre el total de la orden.', 'payment_below_total');
    }

    public static function unitOutsideVendor(): self
    {
        return new self('La unidad pertenece a otro comercio: cada comercio opera su propia caja y sus ventas.', 'unit_outside_vendor');
    }

    public static function invalidQuantity(): self
    {
        return new self('Cada línea vende una cantidad positiva (mínimo 0.001).', 'invalid_quantity');
    }

    public static function exactAmountRequired(): self
    {
        return new self('Tarjeta y transferencia se cobran por el monto exacto: el vuelto solo existe en efectivo.', 'exact_amount_required');
    }

    public static function sessionHasOpenOrders(): self
    {
        return new self('La caja tiene órdenes abiertas: cóbralas o anúlalas antes de cerrar.', 'session_has_open_orders');
    }

    public static function clientRefReused(): self
    {
        return new self(
            'Esa referencia ya registró OTRA venta (contenido o sesión distintos): '
            .'renumera la orden en el dispositivo y reenvía.', 'client_ref_reused'
        );
    }
}
