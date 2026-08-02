<?php

declare(strict_types=1);

namespace App\Domains\Business\Actions;

use App\Domains\Business\Models\Branch;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;

/**
 * Cambios sobre una sucursal existente. Solo tres cosas se tocan —cómo se
 * llama, qué despacha y si sigue abierta— y solo se escriben las que llegan:
 * un formulario parcial no puede borrar en silencio lo que no muestra.
 *
 * Una sucursal NO se borra: se cierra. Sus ventas, sus arqueos y sus
 * movimientos de inventario la referencian para siempre.
 */
class UpdateBranch
{
    public function __invoke(
        Branch $branch,
        ?string $name = null,
        ?OperatingUnitKind $kind = null,
        ?OperatingUnitStatus $status = null,
    ): Branch {
        if ($name !== null) {
            $branch->name = $name;
        }

        if ($kind !== null) {
            $branch->kind = $kind;
        }

        if ($status !== null) {
            $branch->status = $status;
        }

        $branch->save();

        return $branch;
    }
}
