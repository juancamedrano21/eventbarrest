<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los usuarios viven a caballo entre los dos niveles de la plataforma:
 * el staff del SaaS no pertenece a ningún negocio (tenant_id nulo) y los
 * usuarios de negocio pertenecen a uno. Por eso la columna es nullable y
 * User no usa BelongsToTenant — si lo hiciera, el login (que ocurre antes
 * de que exista contexto de tenant) no encontraría a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'email']);
            $table->dropColumn('tenant_id');
        });
    }
};
