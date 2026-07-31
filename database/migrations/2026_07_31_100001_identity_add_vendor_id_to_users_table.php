<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pertenencia de un usuario a un comercio del evento. Nula para el equipo
 * de la cuenta (organizador o negocio independiente); con valor, el usuario
 * opera únicamente dentro de ese comercio.
 *
 * restrictOnDelete: un comercio con gente asignada no se borra por accidente;
 * primero se reasignan o eliminan sus usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('vendor_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('vendors')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
