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

    public static function recipesConsumeThroughTheirRecipe(): self
    {
        return new self(
            'Un producto con receta descuenta inventario por su escandallo: no puede tener un insumo vinculado directo.'
        );
    }

    public static function productHasSales(string $product): self
    {
        return new self(
            "[{$product}] ya tiene ventas registradas: no se borra, se desactiva. ".
            'Borrarlo dejaría ventas apuntando a un producto inexistente.'
        );
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

    public static function categoryOutsideVendor(): self
    {
        return new self('La categoría debe pertenecer al mismo comercio que el producto.');
    }

    public static function ingredientOutsideVendor(): self
    {
        return new self('El insumo debe pertenecer al mismo comercio: las recetas no cruzan comercios.');
    }
}
