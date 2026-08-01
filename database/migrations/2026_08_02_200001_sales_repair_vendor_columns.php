<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparación idempotente: una base local restaurada a mano quedó con la
 * migración de blindaje marcada como ejecutada pero el DDL de orders y
 * cash_sessions a medias (el DDL de MySQL no es transaccional). Cada paso
 * verifica antes de tocar: en un esquema completo no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'vendor_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('vendor_id')->nullable()->after('operating_unit_id')
                    ->constrained('vendors')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('cash_sessions', 'vendor_id')) {
            Schema::table('cash_sessions', function (Blueprint $table): void {
                $table->foreignId('vendor_id')->nullable()->after('operating_unit_id')
                    ->constrained('vendors')->restrictOnDelete();
            });
        }

        $indexes = collect(Schema::getIndexes('orders'))->pluck('name');

        if (! $indexes->contains('orders_client_ref_per_unit')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'operating_unit_id', 'client_ref'], 'orders_client_ref_per_unit');
            });
        }

        if ($indexes->contains('orders_tenant_id_client_ref_unique')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropUnique('orders_tenant_id_client_ref_unique');
            });
        }

        // Backfill: la pertenencia al comercio se deriva de la unidad.
        // Sintaxis de UPDATE..JOIN de MySQL — y solo MySQL puede tener el
        // desfase que esto repara (los tests corren en sqlite fresco).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE orders o JOIN operating_units u ON u.id = o.operating_unit_id SET o.vendor_id = u.vendor_id WHERE o.vendor_id IS NULL');
            DB::statement('UPDATE cash_sessions c JOIN operating_units u ON u.id = c.operating_unit_id SET c.vendor_id = u.vendor_id WHERE c.vendor_id IS NULL');
        }
    }

    public function down(): void
    {
        // Reparación: no hay estado anterior legítimo al que volver.
    }
};
