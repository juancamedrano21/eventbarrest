<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta del asistente y sus dos satélites: sesiones y códigos de entrada.
 *
 * NINGUNA DE LAS TRES LLEVA tenant_id, Y ES LA DECISIÓN QUE DEFINE EL SLICE.
 * La cuenta es el primer actor de PLATAFORMA que no es el superadmin: la
 * identidad que mañana ata boleta, pulsera y monedero A TRAVÉS de eventos de
 * organizadores distintos. El asistente de Bocao es el mismo asistente en el
 * próximo festival; colgarlo de un tenant_id lo partiría en un asistente por
 * organizador y las flechas del doc 11 (boleta ↔ cuenta ↔ pulsera) no
 * podrían cruzar nunca de un evento al siguiente. Por eso las tres tablas
 * están registradas como plataforma en SchemaConventionTest, con este mismo
 * argumento. ADR-011.
 *
 * `token_hash` y `code_hash` guardan sha256, no bcrypt, por el mismo motivo
 * que kds_devices: el token son 64 caracteres aleatorios sin entropía humana
 * que proteger, y el login se resuelve con una igualdad indexada en CADA
 * petición. El código de entrada sí es corto (6 dígitos), pero su defensa no
 * es el hash: es que caduca a los diez minutos, muere al quinto fallo y solo
 * hay uno vigente por email — el hash solo evita que un volcado de la base
 * regale códigos todavía vivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_app_accounts', function (Blueprint $table): void {
            $table->id();

            // Nullable a conciencia: exigir el nombre solo cuando la cuenta
            // es nueva convertiría la validación en un oráculo de existencia
            // (el 422 de «falta el nombre» diría «este email no estaba»).
            // La app enseña el email mientras no haya nombre.
            $table->string('name', 120)->nullable();

            // Normalizado (minúscula, sin espacios) antes de llegar aquí.
            // Único GLOBAL porque la cuenta es global: no hay tenant con el
            // que componer nada.
            $table->string('email')->unique('event_app_accounts_email_unique');

            $table->timestamps();
        });

        Schema::create('event_app_sessions', function (Blueprint $table): void {
            $table->id();

            // cascadeOnDelete y no restrict: borrar la cuenta es el único
            // borrado real de la plataforma (lo exige Apple 5.1.1(v)) y no
            // puede dejar tokens huérfanos ni aunque alguien olvide la línea
            // de código que los borra. La base es el backstop.
            $table->foreignId('event_app_account_id')
                ->constrained('event_app_accounts')
                ->cascadeOnDelete();

            $table->char('token_hash', 64)->unique('event_app_sessions_token_unique');

            // El «sigo viva» de la sesión, con el mismo freno de escritura
            // que la batería del KDS: se persiste como mucho una vez por
            // minuto, no en cada petición.
            $table->timestamp('last_used_at')->nullable();

            // Salir revoca, no borra: la fila cuenta que esa sesión existió
            // mientras la cuenta viva. Lo que sí borra de verdad es borrar
            // la cuenta, que arrastra todo por la foreign key.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });

        Schema::create('event_app_login_codes', function (Blueprint $table): void {
            $table->id();

            // Un solo código vigente por email, garantizado por la base:
            // pedir uno nuevo pisa la fila del anterior, que deja de valer
            // en el acto.
            $table->string('email')->unique('event_app_login_codes_email_unique');

            $table->char('code_hash', 64);

            // El contador que quema el CÓDIGO al quinto fallo. Nunca hay un
            // contador sobre la cuenta: la regla de frenos de la casa — los
            // intentos malos matan lo que se pide otra vez gratis (el
            // código), jamás a la persona.
            $table->unsignedTinyInteger('failed_attempts')->default(0);

            $table->timestamp('expires_at');

            // Caduca → se barre: IssueLoginCode poda los caducados en cada
            // emisión, y ese DELETE tiene que ir sobre índice, no sobre un
            // escaneo de la tabla entera que crece justo cuando más se
            // emite.
            $table->index('expires_at', 'event_app_login_codes_expires_index');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_app_login_codes');
        Schema::dropIfExists('event_app_sessions');
        Schema::dropIfExists('event_app_accounts');
    }
};
