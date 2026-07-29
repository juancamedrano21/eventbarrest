<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La participación: qué negocios van a qué evento, y con qué comisión.
 * Un negocio se da de alta una vez y participa en varias ediciones,
 * conservando su catálogo y su histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_vendor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->unsignedSmallInteger('commission_bps')->default(0)
                ->comment('comisión en puntos básicos: 1000 = 10%');
            $table->timestamps();

            $table->unique(['tenant_id', 'event_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_vendor');
    }
};
