<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Eloquent;

use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Inventory\Models\StockLevel;
use App\Domains\Tenancy\Eloquent\TenantScopedBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * La proyección merece la misma defensa que el libro: los updates masivos
 * no disparan eventos de modelo, así que la cantidad se blinda también aquí.
 *
 * @template TModel of Model
 *
 * @extends TenantScopedBuilder<TModel>
 */
class StockLevelBuilder extends TenantScopedBuilder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $model = $this->getModel();

        // El save() de instancia también pasa por aquí: la bandera de
        // proyección distingue la escritura del libro de un masivo a mano.
        $isLedgerWrite = $model instanceof StockLevel && $model->isProjectionWrite();

        if (! $isLedgerWrite
            && (array_key_exists('quantity', $values) || array_key_exists($model->qualifyColumn('quantity'), $values))) {
            throw InventoryException::projectionIsLedgerOnly();
        }

        return parent::update($values);
    }

    public function delete(): mixed
    {
        throw InventoryException::projectionIsLedgerOnly();
    }
}
