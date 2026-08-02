<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto del producto. Nula mientras nadie la suba: el POS pinta entonces un
 * bloque de color derivado del nombre, que se lee igual de rápido en una
 * barra a media luz.
 *
 * Se guarda la RUTA, no el archivo: el binario vive en el disco público y la
 * base solo apunta. Igual que el logo del comercio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
