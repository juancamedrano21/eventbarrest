<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El segundo nivel de pertenencia en las tablas que un negocio maneja por
 * su cuenta. Nulo en cuentas de negocio (no hay negocios internos: el
 * aislamiento por cuenta basta); relleno en cuentas de organizador.
 *
 * Los índices únicos pasan a incluir vendor_id vía columna generada, para
 * que dos negocios del mismo evento puedan tener ambos su "Mojito" sin
 * chocar — y para que los NULL de las cuentas de negocio no dejen de
 * restringir (en MySQL dos NULL nunca colisionan).
 */
return new class extends Migration
{
    /** @var array<string, string> tabla => columna del índice único de negocio */
    private const TABLES = [
        'categories' => 'name',
        'products' => 'name',
        'inventory_items' => 'name',
        'operating_units' => 'name',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignId('vendor_id')->nullable()->after('tenant_id')
                    ->constrained('vendors')->cascadeOnDelete();
                $blueprint->unsignedBigInteger('vendor_key')->virtualAs('COALESCE(vendor_id, 0)');
                $blueprint->index(['tenant_id', 'vendor_id'], "{$table}_tenant_vendor_idx");
            });
        }

        // Recomponer los únicos incluyendo el negocio.
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'vendor_key', 'name'], 'categories_tenant_vendor_name_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'vendor_key', 'name'], 'products_tenant_vendor_name_unique');
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'vendor_key', 'name'], 'inventory_tenant_vendor_name_unique');
        });

        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropUnique('operating_units_tenant_id_event_key_name_unique');
            $table->unique(['tenant_id', 'vendor_key', 'event_key', 'name'], 'units_tenant_vendor_event_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_tenant_vendor_name_unique');
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_tenant_vendor_name_unique');
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropUnique('inventory_tenant_vendor_name_unique');
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropUnique('units_tenant_vendor_event_name_unique');
            $table->unique(['tenant_id', 'event_key', 'name'], 'operating_units_tenant_id_event_key_name_unique');
        });

        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("{$table}_tenant_vendor_idx");
                $blueprint->dropColumn('vendor_key');
                $blueprint->dropForeign(['vendor_id']);
                $blueprint->dropColumn('vendor_id');
            });
        }
    }
};
