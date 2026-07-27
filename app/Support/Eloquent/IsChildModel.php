<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lado hijo de la herencia sobre una sola tabla (STI).
 *
 * La clase es el mundo: sus consultas solo ven filas de su tipo y sus altas
 * siempre nacen con él. No hay condicional que elegir — el discriminador lo
 * fija la propia clase.
 *
 * Debe usarse en una clase que extienda de un modelo con HasChildModels.
 */
trait IsChildModel
{
    abstract public static function childTypeValue(): string;

    public static function bootIsChildModel(): void
    {
        static::addGlobalScope('sti-child-type', function (Builder $builder): void {
            $model = $builder->getModel();
            $builder->where($model->qualifyColumn(static::childTypeColumn()), static::childTypeValue());
        });

        static::creating(function (Model $model): void {
            $model->setAttribute(static::childTypeColumn(), static::childTypeValue());
        });
    }

    /**
     * Las referencias polimórficas (auditoría, morphs futuros) apuntan a la
     * clase base: la fila es una, aunque cada mundo la vista distinto.
     */
    public function getMorphClass(): string
    {
        $parent = get_parent_class($this);

        return $parent !== false ? $parent : static::class;
    }
}
