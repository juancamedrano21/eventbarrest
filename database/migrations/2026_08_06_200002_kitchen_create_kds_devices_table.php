<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tablet colgada en la ventanilla de un puesto. Es la identidad más rara
 * de la plataforma: no es un usuario, no tiene contraseña que nadie teclee
 * cada mañana y no pertenece a una persona sino a un sitio físico. Por eso
 * se enrola UNA vez —código del comercio más PIN del puesto— y a partir de
 * ahí vive con un token propio y de larga vida, que se revoca de una en una
 * desde el panel el día que la tablet se pierde o se cambia.
 *
 * `token_hash` guarda sha256 del token, no bcrypt. La diferencia con la
 * contraseña de una persona es que aquí no hay entropía humana que proteger:
 * el token son 64 caracteres aleatorios, así que un hash lento no aporta
 * nada frente a la fuerza bruta, y sí impediría lo que de verdad hace falta,
 * que es resolver el login con un índice en CADA petición del tablero (la
 * tablet pregunta cada pocos segundos).
 *
 * vendor_id es NOT NULL por lo mismo que en kitchen_tickets: VendorScope
 * falla ABIERTO, y quien lee esta tabla está fuera de toda sesión de
 * usuario. La columna obligatoria es el último backstop de la base contra
 * que la tablet de un comercio acabe mirando el puesto de su competidor.
 *
 * Las columnas started_by_device_id / ready_by_device_id de kitchen_tickets
 * apuntan aquí y se quedan SIN foreign key a propósito: son rastro
 * histórico de quién tocó la comanda, y una tablet que se dé de baja algún
 * día no debe arrastrar consigo —ni bloquear— el registro de lo que hizo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kds_devices')) {
            return;
        }

        Schema::create('kds_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('operating_unit_id')->constrained()->restrictOnDelete();

            // Cómo la llama la gente del puesto: «Tablet ventanilla», «La de
            // atrás». Es lo único que se ve en la lista del panel a la hora
            // de decidir cuál revocar.
            $table->string('name', 60);

            // Null = ve las DOS áreas. Es el caso normal de un puesto
            // pequeño donde la misma persona sirve la cerveza y el plato;
            // fijarla sirve cuando barra y cocina tienen pantallas propias.
            $table->string('area', 10)->nullable();

            $table->char('token_hash', 64);

            // El latido: cuándo preguntó por última vez. Sirve para saber en
            // el panel si la tablet sigue viva antes de revocar la que toca.
            $table->timestamp('last_seen_at')->nullable();

            // Revocar no borra: el rastro de qué dispositivo movió qué
            // comanda tiene que seguir teniendo a quién apuntar.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // El único compuesto lo exige SchemaConventionTest. Pero el que
            // de verdad resuelve el login es el simple: la tablet presenta
            // su token sin decir de qué cuenta es, así que esa consulta es
            // forzosamente cross-tenant y no puede apoyarse en el compuesto.
            $table->unique(['tenant_id', 'token_hash'], 'kds_devices_tenant_token_unique');
            $table->index('token_hash', 'kds_devices_token_idx');

            // La lista del panel: las tabletas de un puesto.
            $table->index(['tenant_id', 'operating_unit_id'], 'kds_devices_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_devices');
    }
};
