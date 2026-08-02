<?php

declare(strict_types=1);

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\Exceptions\SalesException;
use Illuminate\Support\Facades\DB;

/**
 * El siguiente número de la serie del comercio (o de la cuenta, cuando no
 * hay comercio). Se toma dentro de la transacción de la venta, así que un
 * rollback devuelve el número y la serie no deja huecos — importa para una
 * numeración que un día será fiscal.
 *
 * En MySQL va en UN SOLO statement a propósito. La versión ingenua
 * (SELECT ... FOR UPDATE y luego INSERT si no había fila) provoca
 * DEADLOCKS REALES en la primera venta: el FOR UPDATE sobre una fila
 * inexistente toma un gap lock del índice, dos peticiones lo comparten y
 * después cada INSERT necesita una insert-intention que choca con el gap
 * de la otra. Peor: ese gap abarca (tenant, scope) intermedios, así que la
 * primera venta de una cuenta tumbaba la de otra.
 */
class NextOrderNumber
{
    public function __invoke(int $tenantId, ?int $vendorId): int
    {
        if ($tenantId <= 0) {
            throw SalesException::orderNumberUnavailable();
        }

        $scope = $vendorId ?? 0;

        return DB::connection()->getDriverName() === 'mysql'
            ? $this->takeAtomically($tenantId, $scope)
            : $this->takeWithLock($tenantId, $scope);
    }

    /**
     * Un statement, sin ventana entre leer y escribir: LAST_INSERT_ID(expr)
     * devuelve el valor que tomamos y deja el siguiente listo.
     */
    private function takeAtomically(int $tenantId, int $scope): int
    {
        $ahora = now();

        $filas = DB::affectingStatement(
            'INSERT INTO order_sequences (tenant_id, number_scope, next_number, created_at, updated_at) '
            .'VALUES (?, ?, 2, ?, ?) '
            .'ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number) + 1, updated_at = VALUES(updated_at)',
            [$tenantId, $scope, $ahora, $ahora],
        );

        // MySQL responde 1 cuando insertó (serie nueva: le toca el 1) y 2
        // cuando actualizó (el número tomado quedó en LAST_INSERT_ID).
        if ($filas === 1) {
            return 1;
        }

        $numero = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS numero')->numero;

        return $numero > 0 ? $numero : throw SalesException::orderNumberUnavailable();
    }

    /**
     * Fuera de MySQL (sqlite de los tests) no hay gap locks y la escritura
     * ya está serializada por la base.
     */
    private function takeWithLock(int $tenantId, int $scope): int
    {
        $fila = DB::table('order_sequences')
            ->where('tenant_id', $tenantId)
            ->where('number_scope', $scope)
            ->lockForUpdate()
            ->first();

        if ($fila === null) {
            DB::table('order_sequences')->insert([
                'tenant_id' => $tenantId,
                'number_scope' => $scope,
                'next_number' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 1;
        }

        $numero = (int) $fila->next_number;

        DB::table('order_sequences')
            ->where('id', $fila->id)
            ->update(['next_number' => $numero + 1, 'updated_at' => now()]);

        return $numero;
    }
}
