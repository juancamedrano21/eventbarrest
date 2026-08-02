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
 * CADA PASO TIENE SU PROPIO GUARD y el backfill es reanudable (solo numera
 * lo que aún no tiene número, por lotes). El DDL de MySQL commitea al
 * vuelo: si el proceso muere a mitad del relleno, el reintento retoma
 * donde iba en vez de saltarse el índice único, que es el backstop de que
 * dos ventas jamás compartan número.
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

        if (! Schema::hasColumn('orders', 'channel')) {
            Schema::table('orders', fn (Blueprint $table) => $table->string('channel', 20)->default('pos'));
        }

        if (! Schema::hasColumn('orders', 'order_number')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('order_number')->nullable();
                $table->unsignedBigInteger('number_scope')->default(0);
            });
        }

        $this->backfill();

        if (! Schema::hasIndex('orders', 'orders_public_number_unique')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'number_scope', 'order_number'], 'orders_public_number_unique');
            });
        }
    }

    /**
     * Numera lo ya vendido por orden de creación, continuando la serie que
     * cada comercio tenga. Reanudable: solo toca las órdenes sin número.
     */
    private function backfill(): void
    {
        $contadores = [];

        DB::table('orders')
            ->whereNull('order_number')
            ->orderBy('id')
            ->select('id', 'tenant_id', 'vendor_id')
            ->chunkById(500, function ($ordenes) use (&$contadores): void {
                foreach ($ordenes as $order) {
                    $scope = (int) ($order->vendor_id ?? 0);
                    $clave = $order->tenant_id.':'.$scope;

                    // Continúa desde lo ya numerado (corrida anterior a medias).
                    $contadores[$clave] ??= (int) DB::table('orders')
                        ->where('tenant_id', $order->tenant_id)
                        ->where('number_scope', $scope)
                        ->max('order_number');

                    $numero = ++$contadores[$clave];

                    DB::table('orders')->where('id', $order->id)->update([
                        'order_number' => $numero,
                        'number_scope' => $scope,
                    ]);
                }
            });

        // Los contadores quedan listos para la siguiente venta de cada uno.
        foreach ($contadores as $clave => $ultimo) {
            [$tenantId, $scope] = explode(':', (string) $clave);

            DB::table('order_sequences')->updateOrInsert(
                ['tenant_id' => (int) $tenantId, 'number_scope' => (int) $scope],
                ['next_number' => $ultimo + 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', 'orders_public_number_unique')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropUnique('orders_public_number_unique'));
        }

        foreach (['channel', 'order_number', 'number_scope'] as $columna) {
            if (Schema::hasColumn('orders', $columna)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($columna));
            }
        }

        Schema::dropIfExists('order_sequences');
    }
};
