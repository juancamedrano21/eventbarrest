<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué se reconoce a una tablet que ya estuvo aquí.
 *
 * El problema es de todos los días y se ve en la base de pruebas: seis filas
 * llamadas «Cocina 1» en el mismo puesto, y las seis son la MISMA Galaxy Tab.
 * Cada vez que la tablet perdió el token —o alguien la descolgó y la volvió a
 * colgar— el alta creó una fila nueva, porque hasta hoy el alta no tenía forma
 * de saber que ese aparato ya tenía la suya. Con la batería en el panel el
 * destrozo se ve mejor: cinco tabletas fantasma con una lectura congelada de
 * hace horas, y el organizador sin saber cuál de las seis mirar.
 *
 * QUÉ SE GUARDA AQUÍ, Y QUÉ NO. `Settings.Secure.ANDROID_ID`, que el APK pasa
 * por su puente. NO es el número de serie del aparato: `Build.getSerial()`
 * exige READ_PRIVILEGED_PHONE_STATE desde Android 10 y una app normal no lo
 * tiene, así que devolvería «unknown» en la flota entera y esta columna sería
 * una constante inútil. El ANDROID_ID, en cambio, es estable por aparato Y por
 * clave de firma de la app desde Android 8: sobrevive a reinstalar el APK y a
 * borrar los datos —que es exactamente lo que hace falta— y no deja que otra
 * aplicación reconozca a esa misma tablet.
 *
 * ESTO NO ES UNA CREDENCIAL. Es una etiqueta para no duplicar filas y nada
 * más. Quien presenta una identidad sigue teniendo que dar el código del
 * comercio y el PIN del puesto, sin descuento ninguno. Si algún día se usara
 * para saltarse el PIN, cualquiera que averiguase una cadena de dieciséis
 * caracteres —que no es secreta, y que el propio aparato reparte— entraría en
 * un puesto ajeno.
 *
 * NULLABLE PORQUE ES OPCIONAL DE VERDAD. La pantalla también se abre en un
 * navegador normal, donde no hay puente ni identidad. Una tablet sin identidad
 * se da de alta como hasta ahora, con su fila nueva: el hueco es honesto.
 * Rellenarlo con una huella del navegador —fuentes, canvas, user agent— sería
 * peor: cambia con cada actualización de Chrome y juntaría dos tabletas
 * distintas del mismo modelo en una sola fila.
 *
 * EL ÍNDICE. `tenant_id` delante porque SchemaConventionTest lo exige de todo
 * único, y `operating_unit_id` dentro porque la unidad de «la misma tablet» es
 * «esta tablet EN ESTE puesto»: el mismo aparato prestado mañana a la barra de
 * al lado es otra fila, con su propio rastro de qué comandas despachó desde
 * allí. Los NULL no chocan entre sí ni en MySQL ni en SQLite, y eso es
 * justamente lo que deja convivir a las tabletas sin identidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kds_devices', function (Blueprint $table): void {
            // 64 y no 16: el ANDROID_ID son dieciséis caracteres hex, pero
            // esta columna es el hueco de «cómo se llama a sí mismo este
            // aparato» y el día que el APK corra en algo que no sea Android
            // —o que el identificador se derive antes de mandarlo— no habrá
            // que migrar la tabla para que quepa.
            $table->string('device_identity', 64)->nullable()->after('area');

            $table->unique(
                ['tenant_id', 'operating_unit_id', 'device_identity'],
                'kds_devices_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('kds_devices', function (Blueprint $table): void {
            $table->dropUnique('kds_devices_identity_unique');
            $table->dropColumn('device_identity');
        });
    }
};
