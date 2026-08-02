<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La modalidad de ITBIS: si el precio de carta ya lo lleva incluido o si se
 * suma al cobrar. Es regla del NEGOCIO, no del producto.
 *
 * - tenants.itbis_mode: la regla de la cuenta (default: incluido, como se
 *   vende en la mayoría de los bares de RD).
 * - vendors.itbis_mode: null = hereda la de la cuenta; un comercio de
 *   evento es un negocio tercero y puede tener la suya.
 * - orders.itbis_mode: congelada al vender — cambiar la regla mañana no
 *   reescribe cómo se cobró hoy. El histórico se rellena como «incluido»,
 *   que es como el sistema calculó hasta ahora.
 *
 * Guards idempotentes: el DDL de MySQL no es transaccional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'itbis_mode')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->string('itbis_mode', 20)->default('included');
            });
        }

        if (! Schema::hasColumn('vendors', 'itbis_mode')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->string('itbis_mode', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'itbis_mode')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('itbis_mode', 20)->default('included');
            });
        }
    }

    public function down(): void
    {
        foreach (['tenants', 'vendors', 'orders'] as $table) {
            if (Schema::hasColumn($table, 'itbis_mode')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('itbis_mode'));
            }
        }
    }
};
