<?php

declare(strict_types=1);

namespace App\Domains\Platform\Eloquent;

use App\Domains\Platform\Exceptions\TenantTypeIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los updates masivos no disparan eventos de modelo, así que el hook que
 * protege el tipo de cuenta no llega a correr: se bloquea aquí, espejo de
 * OperatingUnitBuilder con la estructura de las unidades.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TenantBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $model = $this->getModel();

        if (array_key_exists('type', $values) || array_key_exists($model->qualifyColumn('type'), $values)) {
            throw TenantTypeIsImmutableException::forTenant('*');
        }

        return parent::update($values);
    }
}
