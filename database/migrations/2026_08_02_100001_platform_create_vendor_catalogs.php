<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos de PLATAFORMA para clasificar comercios: tipo de negocio (Bar,
 * Restaurante, Otros...) y tipo de comida. Los administra el superadmin en
 * /admin y los usan todas las cuentas — por eso no llevan tenant_id.
 * También: el logo y la clasificación en el propio comercio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('food_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('status');
            $table->foreignId('vendor_type_id')->nullable()->after('logo_path')
                ->constrained('vendor_types')->restrictOnDelete();
            $table->foreignId('food_type_id')->nullable()->after('vendor_type_id')
                ->constrained('food_types')->restrictOnDelete();
        });

        // Los tres de partida; el superadmin crea los demás desde /admin.
        DB::table('vendor_types')->insertOrIgnore([
            ['name' => 'Bar', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Restaurante', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Otros', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('food_type_id');
            $table->dropConstrainedForeignId('vendor_type_id');
            $table->dropColumn('logo_path');
        });
        Schema::dropIfExists('food_types');
        Schema::dropIfExists('vendor_types');
    }
};
