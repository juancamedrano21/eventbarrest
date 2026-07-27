<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El default 'business' permitía que un insert crudo acuñara un negocio en
 * silencio, sin que nadie eligiera mundo. Sin default, la base de datos
 * también exige la elección — última línea de defensa, como siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('type')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('type')->default('business')->change();
        });
    }
};
