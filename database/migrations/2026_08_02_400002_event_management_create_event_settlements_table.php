<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El estado de cuenta de un comercio al cerrar un evento: cuánto vendió,
 * cuánto devolvió, cuánta comisión le toca al organizador y cuánto le queda.
 *
 * Las cifras se GUARDAN, no se recalculan. Una liquidación es un documento
 * que las dos partes miran y sobre el que se paga: si se recalculara al
 * abrir la pantalla, un reembolso tardío o un cambio de ajuste movería una
 * cuenta que ya se cerró de mano. Mismo principio que la venta cobrada.
 *
 * Una fila por evento y comercio. El pago de la comisión se anota encima,
 * porque eso sí ocurre después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('gross_cents')->default(0);
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->unsignedBigInteger('tip_cents')->default(0);
            $table->unsignedBigInteger('itbis_cents')->default(0);

            // Con qué regla y qué porcentaje se calculó, para que la cuenta
            // se pueda explicar dentro de un año sin adivinar.
            $table->string('commission_base', 20);
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedBigInteger('commission_base_cents')->default(0);
            $table->unsignedBigInteger('commission_cents')->default(0);

            // Lo que le queda al comercio de lo que cobró, ya descontada la
            // comisión y lo devuelto.
            $table->bigInteger('net_cents')->default(0);

            $table->timestamp('settled_at');
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_note', 255)->nullable();

            $table->timestamps();

            // Un evento se liquida una vez por comercio.
            $table->unique(['tenant_id', 'event_id', 'vendor_id'], 'settlements_event_vendor_unique');
            $table->index(['tenant_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_settlements');
    }
};
