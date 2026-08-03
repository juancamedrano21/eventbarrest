<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo cobró el cajero de verdad, según el reloj de SU dispositivo.
 *
 * `paid_at` es cuándo se enteró el servidor, y el POS es offline-first: con
 * mal wifi una venta puede cobrarse a las 21:03 y llegar a las 21:11. Sin
 * esta columna el sistema no puede distinguir un pedido nuevo de uno que
 * lleva ocho minutos esperando, y la cocina recibe como recién llegado algo
 * por lo que el cliente ya está reclamando.
 *
 * Es un dato de OPERACIÓN, no de contabilidad: viene de un reloj ajeno y no
 * se usa jamás para dinero, numeración ni cortes de día. Para eso está
 * paid_at, que lo pone el servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('device_sold_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('device_sold_at');
        });
    }
};
