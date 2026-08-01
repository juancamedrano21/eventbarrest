<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El índice único de spatie en modo teams lidera por tenant_id, así que las
 * consultas por nombre de rol a través de TODA la plataforma (conteo de
 * titulares por plantilla, limpieza al borrar una plantilla, TenantOwners)
 * escaneaban la tabla completa. Con name de líder pasan a ser seeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->index(['name', 'guard_name'], 'roles_name_guard_index');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex('roles_name_guard_index');
        });
    }
};
