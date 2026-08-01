<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El dominio de ventas: sesiones de caja, órdenes con sus líneas
 * (instantáneas de nombre y precio: la historia no cambia aunque el catálogo
 * sí) y cobros. Dinero siempre en centavos enteros.
 *
 * client_ref es la clave de idempotencia del POS offline: el cliente genera
 * su referencia y reenviar una orden sincronizada no la duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('operating_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->unsignedBigInteger('opening_cents');
            $table->unsignedBigInteger('closing_cents')->nullable();
            $table->unsignedBigInteger('expected_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // Una sola caja abierta por unidad: columna generada (NULL cuando
        // está cerrada; los NULL no colisionan en el índice único).
        DB::statement(
            'ALTER TABLE cash_sessions ADD COLUMN open_unit_key BIGINT UNSIGNED '
            ."GENERATED ALWAYS AS (IF(status = 'open', operating_unit_id, NULL)) VIRTUAL"
        );
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'open_unit_key'], 'cash_sessions_one_open_per_unit');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('operating_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_ref', 40);
            $table->string('status');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('itbis_cents');
            $table->unsignedBigInteger('tip_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'client_ref']);
            $table->index(['tenant_id', 'operating_unit_id', 'status']);
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 10, 3);
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('method');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cash_sessions');
    }
};
