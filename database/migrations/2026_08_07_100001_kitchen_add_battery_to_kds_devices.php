<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánta batería le queda a la tablet colgada en la ventanilla.
 *
 * No es un capricho de panel. La pantalla de un puesto vive de un cargador
 * que alguien desenchufa para cargar el teléfono, y cuando se apaga no avisa:
 * simplemente deja de haber comandas y la cocina tarda veinte minutos en
 * darse cuenta de que lleva rato sin salir nada. Esto existe para que en el
 * panel se vea antes de que pase.
 *
 * LAS TRES SON NULLABLE A PROPÓSITO, y es la decisión entera de esta
 * migración. `null` significa «no lo sé» —el navegador no supo leerlo, la
 * tablet es vieja, la pantalla se abrió desde un Safari que quitó la API— y
 * tiene que poder distinguirse de `0`, que significa «se está apagando
 * AHORA». Rellenar el hueco con un cero por comodidad convertiría a cada
 * tablet que no sabe leerse la batería en una emergencia permanente, y a los
 * tres días nadie miraría ya ese aviso. Un hueco es un dato honesto.
 *
 * `battery_at` es la tercera columna por la misma razón: sin ella, un 12 %
 * leído a las nueve de la mañana y otro leído hace tres segundos se pintan
 * igual. El nivel sin su hora no se puede interpretar.
 *
 * Sin índice a conciencia. Estas columnas se leen SIEMPRE junto con la lista
 * de tabletas de un puesto, que ya resuelve `kds_devices_unit_idx`; nadie
 * pregunta nunca «dame todas las tabletas de la plataforma con poca
 * batería», y un índice sobre una columna que se reescribe cada minuto por
 * cada tablet se pagaría en cada escritura sin devolver nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kds_devices', function (Blueprint $table): void {
            // 0..100 entra de sobra en un byte y el rango lo defiende la
            // propia columna: un 150 llegado por cabecera no puede aterrizar
            // aquí ni aunque alguien se saltara la validación de arriba.
            $table->unsignedTinyInteger('battery_percent')->nullable()->after('last_seen_at');

            // Enchufada o no. Nullable por lo mismo que el nivel: hay
            // navegadores que dan uno de los dos datos y no el otro.
            $table->boolean('battery_charging')->nullable()->after('battery_percent');

            // De cuándo es la lectura. NO es last_seen_at: la tablet puede
            // seguir preguntando cada tres segundos y haber dejado de saber
            // su propia batería.
            $table->timestamp('battery_at')->nullable()->after('battery_charging');
        });
    }

    public function down(): void
    {
        Schema::table('kds_devices', function (Blueprint $table): void {
            $table->dropColumn(['battery_percent', 'battery_charging', 'battery_at']);
        });
    }
};
