<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La unidad operativa es la columna vertebral de la operación: ventas, stock,
 * cajas, terminales y personal en turno siempre cuelgan de una.
 *
 * `event_id` nulo  ⇒ sucursal de una cuenta de negocio.
 * `event_id` presente ⇒ punto de venta dentro de ese evento (cuenta de
 * organizador). Gracias a esta unificación, POS e inventario son el mismo
 * código en los dos mundos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('type');
            $table->string('kind')->default('mixed');
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'event_id', 'name']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_units');
    }
};
