<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El reembolso de una venta ya cobrada. La venta NO se toca — sigue siendo
 * historia inmutable: el dinero que vuelve es un hecho NUEVO que la
 * referencia, como manda la contabilidad (y como exigirá la DGII, donde
 * esto se convertirá en una nota de crédito B04).
 *
 * cash_session_id es la caja del TURNO EN QUE SE DEVUELVE, no la de la
 * venta original: el efectivo sale de la gaveta que está abierta ahora, y
 * es ese arqueo el que debe cuadrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refunds')) {
            return;
        }

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason');
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'cash_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
