<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use Illuminate\Support\Facades\DB;

/**
 * El siguiente número de la serie del comercio (o de la cuenta, cuando no
 * hay comercio). Se toma CON LOCK dentro de la transacción de la venta:
 * dos cajas cobrando a la vez se serializan aquí y nunca reciben el mismo
 * número. El índice único de orders es el backstop.
 *
 * La fila del contador nace en la primera venta; la carrera del alta la
 * resuelve el unique de order_sequences y una segunda pasada.
 */
class NextOrderNumber
{
    public function __invoke(int $tenantId, ?int $vendorId): int
    {
        $scope = $vendorId ?? 0;

        $numero = $this->take($tenantId, $scope);

        if ($numero !== null) {
            return $numero;
        }

        // Primera venta de este comercio: crea su contador y vuelve a
        // tomarlo con lock (si otra petición lo creó primero, da igual).
        DB::table('order_sequences')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'number_scope' => $scope,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->take($tenantId, $scope) ?? 1;
    }

    private function take(int $tenantId, int $scope): ?int
    {
        $fila = DB::table('order_sequences')
            ->where('tenant_id', $tenantId)
            ->where('number_scope', $scope)
            ->lockForUpdate()
            ->first();

        if ($fila === null) {
            return null;
        }

        $numero = (int) $fila->next_number;

        DB::table('order_sequences')
            ->where('id', $fila->id)
            ->update(['next_number' => $numero + 1, 'updated_at' => now()]);

        return $numero;
    }
}
