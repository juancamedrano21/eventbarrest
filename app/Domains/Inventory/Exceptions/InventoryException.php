<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Exceptions;

use App\Domains\Inventory\Enums\StockMovementType;
use RuntimeException;

class InventoryException extends RuntimeException
{
    public static function ledgerIsImmutable(): self
    {
        return new self(
            'El libro de movimientos no se edita ni se borra: un error se corrige con un ajuste, '.
            'dejando rastro. El stock es la suma de sus movimientos.'
        );
    }

    public static function projectionIsLedgerOnly(): self
    {
        return new self(
            'Las existencias son una proyección del libro de movimientos: la cantidad solo cambia '.
            'con compras, ajustes, mermas y traslados — nunca a mano.'
        );
    }

    public static function quantityCannotBeZero(): self
    {
        return new self('Un movimiento de inventario necesita una cantidad distinta de cero.');
    }

    public static function wrongSign(StockMovementType $type): self
    {
        return new self(match ($type->direction()) {
            1 => "Un movimiento de tipo [{$type->getLabel()}] solo suma stock: la cantidad debe ser positiva.",
            default => "Un movimiento de tipo [{$type->getLabel()}] solo resta stock: la cantidad debe ser negativa.",
        });
    }

    public static function unitOutsideTenant(): self
    {
        return new self('La unidad operativa indicada no pertenece a esta cuenta.');
    }

    public static function itemOutsideTenant(): self
    {
        return new self('El insumo indicado no pertenece a esta cuenta.');
    }

    public static function transferNeedsTwoUnits(): self
    {
        return new self('Una transferencia mueve stock entre dos unidades distintas de la misma cuenta.');
    }

    public static function purchaseNeedsUnitCost(): self
    {
        return new self('Una compra necesita el costo unitario para recalcular el costo promedio del insumo.');
    }

    public static function vendorMismatch(): self
    {
        return new self(
            'El insumo y la unidad deben pertenecer al mismo comercio: cada comercio maneja su propio stock.'
        );
    }

    public static function transferAcrossVendors(): self
    {
        return new self('Entre comercios no hay traslados: cada comercio mueve su propio stock.');
    }
}
