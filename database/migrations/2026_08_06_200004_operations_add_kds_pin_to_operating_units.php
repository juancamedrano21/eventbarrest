<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El PIN del puesto: lo único secreto del enrolamiento de una tablet. Vive
 * en la unidad operativa y no en el comercio porque lo que se autoriza es
 * mirar UNA ventanilla — el encargado de la barra norte no tiene por qué
 * poder colgar una tablet en la cocina sur.
 *
 * Los dos contadores del bloqueo están en la BASE y no en caché a
 * propósito. El código del comercio es público por diseño, así que la
 * puerta admite intentos a ciegas y necesita un freno; y CACHE_STORE es
 * database, un almacén que se vacía con un comando de mantenimiento
 * cualquiera. Un freno que se borra al limpiar la caché no es un freno.
 *
 * `kds_pin_set_at` es para el panel: saber desde cuándo circula ese PIN es
 * lo que empuja a rotarlo cuando el montaje ha pasado por muchas manos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            $table->string('kds_pin_hash')->nullable();
            $table->timestamp('kds_pin_set_at')->nullable();
            $table->unsignedTinyInteger('kds_pin_failed_attempts')->default(0);
            $table->timestamp('kds_pin_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropColumn([
                'kds_pin_hash',
                'kds_pin_set_at',
                'kds_pin_failed_attempts',
                'kds_pin_locked_until',
            ]);
        });
    }
};
