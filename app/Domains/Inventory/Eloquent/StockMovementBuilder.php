<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Eloquent;

use App\Domains\Inventory\Exceptions\InventoryException;
use App\Domains\Tenancy\Eloquent\TenantScopedBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los updates y deletes masivos no disparan eventos de modelo, así que la
 * inmutabilidad del libro se bloquea también aquí.
 *
 * @template TModel of Model
 *
 * @extends TenantScopedBuilder<TModel>
 */
class StockMovementBuilder extends TenantScopedBuilder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw InventoryException::ledgerIsImmutable();
    }

    public function delete(): mixed
    {
        throw InventoryException::ledgerIsImmutable();
    }
}
