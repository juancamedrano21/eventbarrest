<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Guards the two schema rules a shared-database design depends on. Raw SQL
 * bypasses every application-level guard, so the database itself has to be
 * the last line of defence. These tests pass vacuously until the first
 * business table exists — and fail loudly the day one arrives without them.
 */
$platformTables = [
    'migrations',
    'tenants',
    'users',
    'password_reset_tokens',
    'sessions',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'personal_access_tokens',
    'activity_log',
    // Tablas de spatie/laravel-permission. Tienen su propio modelo de
    // aislamiento (modo teams con tenant_id) que el paquete aplica por su
    // cuenta, y los permisos son deliberadamente globales: lo que pertenece a
    // cada negocio son los roles y sus asignaciones, no el catálogo de
    // permisos. IdentityIsolationTest verifica que ese aislamiento funciona.
    'permissions',
    'roles',
    'model_has_permissions',
    'model_has_roles',
    'role_has_permissions',
    // Catálogo de roles de la PLATAFORMA: lo administra el superadmin y se
    // materializa por cuenta en las tablas de spatie; la plantilla no
    // pertenece a ningún tenant y su name es único a nivel global adrede.
    'role_templates',
];

$businessTables = fn (): array => collect(Schema::getTableListing(schemaQualified: false))
    ->reject(fn (string $table): bool => in_array($table, $platformTables, true))
    ->values()
    ->all();

it('gives every business table a NOT NULL tenant_id', function () use ($businessTables): void {
    foreach ($businessTables() as $table) {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'tenant_id');

        $this->assertNotNull($column, "Table [{$table}] is missing a tenant_id column.");
        $this->assertFalse($column['nullable'], "Column [{$table}.tenant_id] must be NOT NULL.");
    }
});

it('composes every unique index with tenant_id', function () use ($businessTables): void {
    foreach ($businessTables() as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['unique'] || $index['primary']) {
                continue;
            }

            $this->assertContains(
                'tenant_id',
                $index['columns'],
                "Unique index [{$index['name']}] on [{$table}] must include tenant_id: otherwise an ".
                "upsert from one tenant can resolve its conflict against another tenant's row."
            );
        }
    }
});
