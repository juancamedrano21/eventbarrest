<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscalidad por producto y comisión congelada por venta.
 *
 * - products.itbis_exempt: hay menú que no grava ITBIS (agua embotellada,
 *   alimentos exentos); el desglose pasa a calcularse LÍNEA a LÍNEA.
 * - order_lines.itbis_cents: el desglose congelado por línea. El histórico
 *   se rellena como gravado: así se vendió.
 * - orders.commission_bps: la comisión del organizador pactada en el
 *   momento de la venta. Renegociar o desinvitar al comercio jamás
 *   reescribe lo ya cobrado; el histórico se rellena del pivote vigente.
 * - índice (tenant_id, status, paid_at): los agregados del dashboard.
 *
 * Guards idempotentes en todo: el DDL de MySQL no es transaccional y un
 * fallo a mitad debe poder re-ejecutarse sin romper.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'itbis_exempt')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('itbis_exempt')->default(false);
            });
        }

        if (! Schema::hasColumn('order_lines', 'itbis_cents')) {
            Schema::table('order_lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('itbis_cents')->default(0);
            });

            // Query builder a propósito: la inmutabilidad del historial es
            // de la capa Eloquent y esto es reparación de esquema, no una
            // edición de ventas. ROUND existe igual en MySQL y SQLite.
            DB::table('order_lines')->update([
                'itbis_cents' => DB::raw('ROUND(total_cents * 18.0 / 118)'),
            ]);
        }

        if (! Schema::hasColumn('orders', 'commission_bps')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unsignedSmallInteger('commission_bps')->nullable();
            });

            // Backfill del histórico con la participación vigente (lo mejor
            // que se sabe hoy); desde aquí, cada venta congela la suya.
            $participaciones = DB::table('orders as o')
                ->join('operating_units as u', 'u.id', '=', 'o.operating_unit_id')
                ->join('event_vendor as ev', function ($join): void {
                    $join->on('ev.event_id', '=', 'u.event_id')
                        ->on('ev.vendor_id', '=', 'o.vendor_id')
                        ->on('ev.tenant_id', '=', 'o.tenant_id');
                })
                ->pluck('ev.commission_bps', 'o.id');

            foreach ($participaciones as $orderId => $bps) {
                DB::table('orders')->where('id', $orderId)->update(['commission_bps' => $bps]);
            }
        }

        if (! Schema::hasIndex('orders', 'orders_dashboard_aggregates_index')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(['tenant_id', 'status', 'paid_at'], 'orders_dashboard_aggregates_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', 'orders_dashboard_aggregates_index')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex('orders_dashboard_aggregates_index');
            });
        }

        if (Schema::hasColumn('orders', 'commission_bps')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('commission_bps'));
        }

        if (Schema::hasColumn('order_lines', 'itbis_cents')) {
            Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('itbis_cents'));
        }

        if (Schema::hasColumn('products', 'itbis_exempt')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('itbis_exempt'));
        }
    }
};
