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
    // Catálogos de plataforma para clasificar comercios (admin los gestiona).
    'vendor_types',
    'food_types',
    // La cuenta del asistente y sus satélites (sesiones y códigos de
    // entrada) son de PLATAFORMA a conciencia: el primer actor que no es el
    // superadmin y vive fuera de toda cuenta de negocio. Es la identidad que
    // mañana ata boleta, pulsera y monedero A TRAVÉS de eventos de
    // organizadores distintos — el asistente de Bocao es el mismo asistente
    // en el próximo festival, y un tenant_id lo partiría en una identidad
    // por organizador. Sus índices únicos (email, token) son globales por lo
    // mismo: no hay tenant con el que componerlos. ADR-011.
    'event_app_accounts',
    'event_app_sessions',
    'event_app_login_codes',
];

/**
 * Los únicos índices únicos GLOBALES que la plataforma acepta, por nombre.
 * Uno por uno y con su motivo escrito: si algún día hay que añadir otro,
 * que cueste explicarlo aquí antes que en producción.
 *
 * `vendors_kds_code_unique_global` — el código que se teclea en una tablet
 * del KDS para decir de qué comercio es. La tablet lo teclea SIN saber a
 * qué cuenta pertenece su comercio, así que resolverlo es forzosamente
 * cross-tenant y componer el índice con tenant_id lo dejaría inservible:
 * dos organizadores podrían repartir el mismo código y la tablet no tendría
 * forma de elegir.
 *
 * La excepción no relaja nada, aprieta. Un único global es estrictamente
 * MÁS restrictivo que el compuesto — hace la colisión IMPOSIBLE en vez de
 * solo detectable dentro de una cuenta. Y el peligro concreto que persigue
 * la regla —que un upsert de una cuenta resuelva su conflicto contra la
 * fila de otra— aquí no puede darse: TenantScopedBuilder::upsert() lanza
 * SIEMPRE, para cualquier modelo con BelongsToTenant, sin excepción.
 *
 * `events_public_code_unique_global` — el código que la app del asistente
 * lleva compilado dentro para decir de qué evento es. Mismo argumento: un
 * teléfono recién descargado pregunta por ese código SIN saber a qué cuenta
 * pertenece el festival, así que resolverlo es forzosamente cross-tenant y
 * componer el índice con tenant_id permitiría que dos organizadores
 * repartieran el mismo código sin que ninguna app supiera cuál es la suya.
 *
 * @var array<int, string>
 */
$globalUniqueIndexes = [
    'vendors_kds_code_unique_global',
    'events_public_code_unique_global',
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

it('composes every unique index with tenant_id', function () use ($businessTables, $globalUniqueIndexes): void {
    foreach ($businessTables() as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['unique'] || $index['primary']) {
                continue;
            }

            // Exceptuado por nombre, con su motivo arriba: la lista es corta
            // a propósito y nadie entra en ella sin argumentarlo.
            if (in_array($index['name'], $globalUniqueIndexes, true)) {
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
