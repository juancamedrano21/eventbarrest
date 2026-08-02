<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El número de orden legible: P0041, M0042, W0043 — letra del canal y una
 * serie por COMERCIO (por cuenta en el mundo negocio, que no tiene
 * comercios). El UUID del POS sigue existiendo para la idempotencia; esto
 * es lo que el cliente dicta por teléfono.
 *
 * number_scope es vendor_id, o 0 cuando la venta no es de un comercio: sin
 * él el índice único no serviría, porque MySQL no considera iguales dos
 * NULL y dejaría repetir números en el mundo negocio.
 *
 * El histórico se numera por orden de creación, respetando esa misma
 * partición: las ventas viejas también tienen su número.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_sequences')) {
            Schema::create('order_sequences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
                // 0 = la cuenta entera (mundo negocio, sin comercios).
                $table->unsignedBigInteger('number_scope')->default(0);
                $table->unsignedBigInteger('next_number')->default(1);
                $table->timestamps();

                $table->unique(['tenant_id', 'number_scope']);
            });
        }

        if (! Schema::hasColumn('orders', 'order_number')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('channel', 20)->default('pos');
                $table->unsignedBigInteger('order_number')->nullable();
                $table->unsignedBigInteger('number_scope')->default(0);
            });

            $this->backfill();

            Schema::table('orders', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'number_scope', 'order_number'], 'orders_public_number_unique');
            });
        }
    }

    /**
     * Numera lo ya vendido por orden de creación, y deja cada contador
     * listo para la siguiente venta.
     */
    private function backfill(): void
    {
        $contadores = [];

        DB::table('orders')->orderBy('id')->select('id', 'tenant_id', 'vendor_id')
            ->each(function (object $order) use (&$contadores): void {
                $scope = (int) ($order->vendor_id ?? 0);
                $clave = $order->tenant_id.':'.$scope;
                $numero = ($contadores[$clave] ?? 0) + 1;
                $contadores[$clave] = $numero;

                DB::table('orders')->where('id', $order->id)->update([
                    'order_number' => $numero,
                    'number_scope' => $scope,
                ]);
            });

        foreach ($contadores as $clave => $ultimo) {
            [$tenantId, $scope] = explode(':', (string) $clave);

            DB::table('order_sequences')->insert([
                'tenant_id' => (int) $tenantId,
                'number_scope' => (int) $scope,
                'next_number' => $ultimo + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'order_number')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropUnique('orders_public_number_unique');
                $table->dropColumn(['channel', 'order_number', 'number_scope']);
            });
        }

        Schema::dropIfExists('order_sequences');
    }
};
