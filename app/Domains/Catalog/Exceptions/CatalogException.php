<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use RuntimeException;

class CatalogException extends RuntimeException
{
    public static function recipeNeedsARecipeProduct(): self
    {
        return new self(
            'La receta pertenece a un producto de tipo "con receta": un producto sencillo no tiene escandallo.'
        );
    }

    public static function ingredientOutsideTenant(): self
    {
        return new self('El insumo indicado no pertenece a esta cuenta.');
    }

    public static function typeIsImmutable(): self
    {
        return new self(
            'El tipo de un producto define su costo y su consumo: se elige al crear y no cambia. '.
            'Si el producto cambió de naturaleza, desactívalo y crea otro.'
        );
    }

    public static function categoryOutsideTenant(): self
    {
        return new self('La categoría indicada no pertenece a esta cuenta.');
    }

    public static function productOutsideTenant(): self
    {
        return new self('El producto indicado no pertenece a esta cuenta.');
    }
}
