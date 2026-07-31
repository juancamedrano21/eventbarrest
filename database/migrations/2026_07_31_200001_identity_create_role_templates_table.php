<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las plantillas de rol de la plataforma: la fuente de la que se aprovisiona
 * el juego de roles de cada cuenta. Los 7 roles del código se siembran como
 * plantillas de sistema; el superadmin puede crear más y ajustar permisos, y
 * cada cambio se propaga a todas las cuentas.
 *
 * Es tabla de PLATAFORMA (sin tenant_id): la identidad del rol en cada
 * cuenta es su name, que las filas de spatie replican por tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('kind');
            $table->boolean('is_system')->default(false);
            $table->json('permissions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_templates');
    }
};
