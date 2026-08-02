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
 * El update acotado a UNA clave sí pasa (es la forma del save), pero no a
 * ciegas: el modelo decide si esa fila concreta admite cambios — si no,
 * `Order::query()->whereKey($id)->update([...])` reescribiría una venta
 * cobrada saltándose los eventos.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends TenantScopedBuilder<TModel>
 */
class SalesHistoryBuilder extends TenantScopedBuilder
{
    public function update(array $values): int
    {
        $this->assertSingleRowWrite();

        return parent::update($values);
    }

    public function delete(): mixed
    {
        $this->assertSingleRowWrite();

        return parent::delete();
    }

    private function assertSingleRowWrite(): void
    {
        if (! $this->constrainedToSingleRowByKey()) {
            throw SalesException::paidOrdersAreHistory();
        }

        $model = $this->getModel();

        // El guard de la fila vive en el modelo (el builder no conoce
        // estados): quien tenga historia que proteger lo implementa.
        if (method_exists($model, 'assertRowIsWritable')) {
            foreach ($this->keysBeingWritten() as $key) {
                $model->assertRowIsWritable($key);
            }
        }
    }

    /**
     * El save de un modelo llega aquí acotado a SU clave (setKeysForSaveQuery)
     * y lo gobiernan los guards de eventos; lo que se bloquea es el update o
     * delete masivo, que los eventos jamás verían.
     */
    private function constrainedToSingleRowByKey(): bool
    {
        return $this->keysBeingWritten() !== [];
    }

    /**
     * Las claves a las que está acotada la escritura, si lo está.
     *
     * @return array<int, mixed>
     */
    private function keysBeingWritten(): array
    {
        // El save usa la clave sin calificar; whereKey la califica.
        $columns = [$this->getModel()->getKeyName(), $this->getModel()->getQualifiedKeyName()];
        $keys = [];

        foreach ($this->getQuery()->wheres as $where) {
            if (in_array($where['column'] ?? null, $columns, true) && ($where['operator'] ?? '=') === '=') {
                $keys[] = $where['value'] ?? null;
            }
        }

        return $keys;
    }
}
