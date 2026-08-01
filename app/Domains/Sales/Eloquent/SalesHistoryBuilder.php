<?php

declare(strict_types=1);

namespace App\Domains\Sales\Eloquent;

use App\Domains\Sales\Exceptions\SalesException;
use App\Domains\Tenancy\Eloquent\TenantScopedBuilder;

/**
 * Las ventas son historia también frente al query builder: los guards de
 * modelo (updating/deleting) no ven un update/delete masivo, así que se
 * bloquea aquí. Toda transición legítima pasa por las acciones del dominio
 * (save de modelo), nunca por updates masivos.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends TenantScopedBuilder<TModel>
 */
class SalesHistoryBuilder extends TenantScopedBuilder
{
    public function update(array $values): int
    {
        if (! $this->constrainedToSingleRowByKey()) {
            throw SalesException::paidOrdersAreHistory();
        }

        return parent::update($values);
    }

    public function delete(): mixed
    {
        if (! $this->constrainedToSingleRowByKey()) {
            throw SalesException::paidOrdersAreHistory();
        }

        return parent::delete();
    }

    /**
     * El save de un modelo llega aquí acotado a SU clave (setKeysForSaveQuery)
     * y lo gobiernan los guards de eventos; lo que se bloquea es el update o
     * delete masivo, que los eventos jamás verían.
     */
    private function constrainedToSingleRowByKey(): bool
    {
        // El save usa la clave sin calificar; whereKey la califica.
        $keys = [$this->getModel()->getKeyName(), $this->getModel()->getQualifiedKeyName()];

        foreach ($this->getQuery()->wheres as $where) {
            if (in_array($where['column'] ?? null, $keys, true) && ($where['operator'] ?? '=') === '=') {
                return true;
            }
        }

        return false;
    }
}
