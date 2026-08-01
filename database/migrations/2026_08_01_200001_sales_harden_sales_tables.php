<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blindaje tras la revisión adversarial del dominio de ventas:
 *
 * - Un solo cobro por orden (unique): un doble cobro que escape al lock
 *   revienta por restricción y hace rollback del stock.
 * - tendered/change: lo entregado y el vuelto quedan registrados (el
 *   amount sigue siendo lo que entra a la gaveta).
 * - La idempotencia de client_ref se acota a la UNIDAD: dos comercios (o
 *   dos dispositivos) con la misma referencia no chocan entre sí.
 * - vendor_id en caja y orden: el aislamiento por comercio del resto de la
 *   plataforma llega a las ventas (BelongsToVendor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('tendered_cents')->nullable()->after('amount_cents');
            $table->bigInteger('change_cents')->nullable()->after('tendered_cents');
            $table->unique(['tenant_id', 'order_id'], 'payments_one_per_order');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'client_ref']);
            $table->unique(['tenant_id', 'operating_unit_id', 'client_ref'], 'orders_client_ref_per_unit');
            $table->foreignId('vendor_id')->nullable()->after('operating_unit_id')
                ->constrained('vendors')->restrictOnDelete();
        });

        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable()->after('operating_unit_id')
                ->constrained('vendors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropUnique('orders_client_ref_per_unit');
            $table->unique(['tenant_id', 'client_ref']);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_one_per_order');
            $table->dropColumn(['tendered_cents', 'change_cents']);
        });
    }
};
