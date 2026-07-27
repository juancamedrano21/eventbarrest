<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La existencia actual de cada insumo en cada unidad operativa. Es una
 * proyección del libro de movimientos, mantenida transaccionalmente: nunca
 * se edita a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('alert_threshold', 12, 3)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'operating_unit_id', 'inventory_item_id'], 'stock_levels_tenant_unit_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
