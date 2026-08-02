<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sobre qué dinero se cobra la comisión del organizador.
 *
 * Dos columnas, no una:
 *
 * - `tenants.commission_base` es el AJUSTE: lo que el organizador elige y
 *   rige de ahí en adelante. Por defecto «total», que es lo que el sistema
 *   venía haciendo — nadie ve cambiar su dinero sin decidirlo.
 * - `orders.commission_base` es el SNAPSHOT: con qué regla se calculó ESA
 *   venta. Se congela igual que `commission_bps`, el precio y el ITBIS de
 *   cada línea. Sin ella, cambiar el ajuste reescribiría la liquidación de
 *   un evento ya cerrado.
 *
 * Las órdenes anteriores quedan en nulo, que se lee como «total»: es la regla
 * que se les aplicó cuando se cobraron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('commission_base', 20)->default('total')->after('itbis_mode');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('commission_base', 20)->nullable()->after('commission_bps');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('commission_base');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('commission_base');
        });
    }
};
