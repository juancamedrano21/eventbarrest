<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El libro mayor del inventario: cada entrada y salida, con signo. Es
 * inmutable — un error se corrige con un ajuste, dejando rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('operating_unit_id')->constrained('operating_units');
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->string('type');
            $table->decimal('quantity', 12, 3)->comment('con signo: entradas positivas, salidas negativas');
            $table->unsignedBigInteger('unit_cost_cents')->nullable()->comment('solo compras: costo unitario pagado');
            $table->string('reference')->nullable()->comment('agrupa movimientos hermanos, p. ej. las dos patas de una transferencia');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'operating_unit_id', 'inventory_item_id'], 'stock_movements_tenant_unit_item_index');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
