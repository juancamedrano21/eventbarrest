<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La racha de intentos a ciegas se muda al COMERCIO, que es de quien es.
 *
 * Un intento de alta fallido no identifica ningún puesto —eso es exactamente
 * lo que garantiza el índice ciego: el índice se deriva del propio PIN, así
 * que si señala un puesto, el bcrypt cuadra—. Lo único que un intento fallido
 * identifica es el COMERCIO, porque trae su código. Contar esa racha en
 * columnas del puesto obligaba a replicar el mismo dato en las treinta barras
 * y a leerlo como si fuera de cada una.
 *
 * Y ESO NO ERA SOLO FEO, MENTÍA EN PANTALLA. `kds_pin_locked_until` es la
 * columna que el panel del organizador pinta como «este puesto está
 * bloqueado». Diez peticiones anónimas contra un código que está impreso y
 * pegado en la pared encendían ese cartel en las treinta barras del comercio a
 * la vez, sin que ninguna estuviera bloqueada —el PIN correcto entra igual, con
 * la racha encendida y con ella apagada—. A las dos de la madrugada, un
 * organizador que lee «Bloqueado» en sus treinta barras concluye que su cocina
 * no puede colgar tabletas y llama a alguien. El botón «Desbloquear» de al lado
 * limpiaba UNA fila de las treinta, así que tampoco apagaba lo que decía.
 *
 * `kds_blind_attempts` es la racha de fallos a ciegas seguidos contra el código
 * del comercio, y `kds_blind_pause_until` hasta cuándo dejamos de comprar CPU
 * para contestar que no a lo que ya se sabe que es que no. NINGUNA DE LAS DOS
 * DECIDE QUIÉN ENTRA, y ese es el punto entero: se llaman «pausa» y no
 * «bloqueo» porque no bloquean nada. El porqué, con los números de lo que sí
 * acota adivinar un PIN, está en EnrollKdsDevice::anotarFallo.
 *
 * Se retiran `kds_pin_failed_attempts` y `kds_pin_locked_until` de
 * `operating_units` en la misma migración: al mudar el hecho, ningún sitio de
 * la aplicación las escribe ni las lee, y una columna huérfana con nombre de
 * bloqueo es la que vuelve a encender un cartel falso dentro de dos vueltas.
 *
 * SMALLINT Y NO TINYINT para la cuenta: el techo son diez, pero es una cuenta
 * que sube quien llama y un tinyint desborda a los 255 con un error de la base
 * en vez de un número grande. El freno vive aquí y no en caché a propósito:
 * CACHE_STORE es database y se vacía con cualquier comando de mantenimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('kds_blind_attempts')->default(0);
            $table->timestamp('kds_blind_pause_until')->nullable();
        });

        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropColumn(['kds_pin_failed_attempts', 'kds_pin_locked_until']);
        });
    }

    public function down(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            $table->unsignedTinyInteger('kds_pin_failed_attempts')->default(0);
            $table->timestamp('kds_pin_locked_until')->nullable();
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropColumn(['kds_blind_attempts', 'kds_blind_pause_until']);
        });
    }
};
