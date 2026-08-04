<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El índice ciego del PIN: para saber CONTRA QUÉ PUESTO hay que gastar el
 * bcrypt, sin gastar uno por puesto para averiguarlo.
 *
 * El alta de una tablet llega con el código del comercio —que está impreso y
 * pegado en el puesto, a la vista del recinto— y seis dígitos. Hasta aquí, la
 * única forma de saber a qué puesto pertenecían esos seis dígitos era probar
 * el bcrypt contra TODOS los puestos del comercio: medido, treinta bcrypt en
 * una sola petición anónima contra un comercio de treinta barras. Eso convertía
 * una petición sin credenciales en un multiplicador de CPU que elige quien
 * llama.
 *
 * Racionar ese abanico se intentó dos veces y las dos veces produjo algo peor
 * que el gasto: un contador que sube quien ataca deja fuera al comercio entero
 * —cocinas que no pueden colgar su tablet con el PIN CORRECTO—. Así que el
 * abanico no se raciona: se elimina. `kds_pin_index` guarda, al lado del
 * bcrypt, un HMAC-SHA256 del PIN con el secreto de la aplicación, que localiza
 * el puesto y deja el bcrypt en UNO por petición, pase lo que pase y falle
 * quien falle.
 *
 * ES UN LOCALIZADOR, NO UNA CREDENCIAL. El bcrypt sigue siendo quien decide:
 * el índice solo dice a quién preguntárselo. El porqué entero, y lo que se
 * pierde con la base Y la APP_KEY robadas, está escrito en EnrollKdsDevice.
 *
 * POR QUÉ SON DOS COLUMNAS Y NO UNA. `kds_pin_indexed_hash` guarda la huella de
 * las TRES cosas de las que depende un índice: el comercio con el que se saló, el
 * `kds_pin_hash` para el que se calculó y la LLAVE con la que se derivó. Sin la
 * del comercio, el índice tendría tres entradas y su huella cubriría dos, que es
 * la forma exacta del agujero de la llave. Sin la del hash, rotar el PIN
 * —que reescribe el hash y no el índice— dejaría un índice del PIN VIEJO al lado
 * del hash del NUEVO; sin la de la llave, cambiar la APP_KEY dejaría todos los
 * índices inservibles CUADRANDO, y entonces ningún puesto caería al camino de
 * respaldo y el cocinero que teclea BIEN recibiría «revisa el código y el PIN»
 * en toda la plataforma a la vez, sin que nada lo explicara: la avería invisible
 * que este cambio viene a quitar. Con la huella completa, un índice que no
 * corresponde sencillamente no se usa, y ese puesto vuelve al camino de antes
 * hasta que se reindexe. La invariante no depende de que ninguna acción se
 * acuerde de nada. El detalle está en EnrollKdsDevice::huellaDelIndice.
 *
 * NULABLES A PROPÓSITO, Y NO SE RELLENAN AQUÍ. El índice se deriva del PIN EN
 * CLARO, y el PIN en claro no está en ninguna parte: solo existe el bcrypt. Así
 * que los PIN que ya circulan no se pueden indexar desde una migración —ni
 * desde ningún sitio— sin volver a emitirlos, y rellenarlos a la fuerza sería
 * invalidar en silencio los PIN que hay impresos en las hojas del montaje. Un
 * puesto sin índice sigue entrando por el camino de antes (ver
 * EnrollKdsDevice::intentoDelPin) y se indexa solo la primera vez que alguien
 * teclea bien su PIN. Ese es el residuo de los PIN emitidos antes de esta
 * columna, no el caso normal: los que emite el panel nacen ya indexados, porque
 * RotateOutletKdsPin escribe las dos columnas junto al hash.
 *
 * El índice va acompañado de vendor_id en su índice de base porque la búsqueda
 * pregunta siempre por los dos —EnrollKdsDevice::senaladoPorElIndice hace un
 * where sobre esa pareja exacta— y el aislamiento entre comercios no depende de
 * que el índice sea único en la plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            // 64 caracteres exactos las dos: el hexadecimal de un SHA-256.
            $table->string('kds_pin_index', 64)->nullable()->after('kds_pin_hash');
            $table->string('kds_pin_indexed_hash', 64)->nullable()->after('kds_pin_index');

            $table->index(['vendor_id', 'kds_pin_index'], 'units_vendor_kds_pin_index_idx');
        });
    }

    public function down(): void
    {
        Schema::table('operating_units', function (Blueprint $table): void {
            $table->dropIndex('units_vendor_kds_pin_index_idx');
            $table->dropColumn(['kds_pin_index', 'kds_pin_indexed_hash']);
        });
    }
};
