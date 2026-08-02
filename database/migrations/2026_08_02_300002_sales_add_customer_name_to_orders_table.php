<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A nombre de quién va la orden. Es lo que el cajero grita cuando el pedido
 * sale de la cocina, y lo que lleva impreso la comanda para que nadie se
 * lleve el plato de otro.
 *
 * Nulo mientras nadie lo escriba: pedir el nombre en una barra llena es
 * fricción, y la mayoría de las ventas no lo necesitan. Se congela con la
 * venta como todo lo demás — una orden cobrada es historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_name', 60)->nullable()->after('client_ref');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('customer_name');
        });
    }
};
