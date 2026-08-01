<?php

declare(strict_types=1);

namespace App\Domains\Sales\Exceptions;

use RuntimeException;

class SalesException extends RuntimeException
{
    public static function sessionAlreadyOpen(string $unit): self
    {
        return new self("[{$unit}] ya tiene una caja abierta: ciérrala antes de abrir otra.");
    }

    public static function sessionNotOpen(): self
    {
        return new self('La caja no está abierta: sin sesión de caja no hay ventas ni cierres.');
    }

    public static function orderNeedsLines(): self
    {
        return new self('Una orden necesita al menos una línea.');
    }

    public static function orderNotOpen(): self
    {
        return new self('Solo una orden abierta se puede cobrar o anular.');
    }

    public static function productNotSellable(string $product): self
    {
        return new self("[{$product}] no está activo: no se puede vender.");
    }

    public static function unitOutsideTenant(): self
    {
        return new self('La unidad indicada no pertenece a esta cuenta.');
    }

    public static function lineOutsideOrderVendor(): self
    {
        return new self('Cada línea vende un producto del comercio de la unidad: no se cruzan comercios.');
    }

    public static function paidOrdersAreHistory(): self
    {
        return new self('Una orden cobrada es historia: se anula con su acción, nunca se edita.');
    }

    public static function paymentBelowTotal(): self
    {
        return new self('El cobro no cubre el total de la orden.');
    }
}
