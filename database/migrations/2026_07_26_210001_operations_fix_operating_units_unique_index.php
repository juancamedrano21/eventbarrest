<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El índice único (tenant_id, event_id, name) no restringía nada en las
 * sucursales: en MySQL y SQLite dos NULL no colisionan, así que una cuenta
 * podía tener dos sucursales con el mismo nombre.
 *
 * Se sustituye por una columna generada que convierte el NULL en 0, de modo
 * que el mismo índice cubre los dos mundos.
 *
 * Los pasos van sueltos y comprobados porque MySQL no hace DDL transaccional:
 * si uno falla, el siguiente intento no debe tropezar con lo ya hecho.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('operating_units_tenant_id_event_id_name_unique')) {
            Schema::table('operating_units', function (Blueprint $table): void {
                $table->dropUnique(['tenant_id', 'event_id', 'name']);
            });
        }

        if (! Schema::hasColumn('operating_units', 'event_key')) {
            Schema::table('operating_units', function (Blueprint $table): void {
                $table->unsignedBigInteger('event_key')->virtualAs('COALESCE(event_id, 0)');
            });
        }

        if (! $this->hasIndex('operating_units_tenant_id_event_key_name_unique')) {
            Schema::table('operating_units', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'event_key', 'name']);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('operating_units_tenant_id_event_key_name_unique')) {
            Schema::table('operating_units', function (Blueprint $table): void {
                $table->dropUnique(['tenant_id', 'event_key', 'name']);
            });
        }

        if (Schema::hasColumn('operating_units', 'event_key')) {
            Schema::table('operating_units', function (Blueprint $table): void {
                $table->dropColumn('event_key');
            });
        }

        if (! $this->hasIndex('operating_units_tenant_id_event_id_name_unique')) {
            Schema::table('operating_units', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'event_id', 'name']);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('operating_units'))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
