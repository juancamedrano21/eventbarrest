<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cerebro del white-label: qué marca lleva la app de este evento y qué
 * módulos enseña. El binario es un cascarón que al arrancar pide esto y se
 * construye a sí mismo, así que cambiar un color o encender un módulo no
 * puede exigir recompilar ni pasar por revisión de tienda.
 *
 * POR QUÉ COLUMNAS TIPADAS PARA LA MARCA Y JSON PARA LOS MÓDULOS. No es
 * comodidad: son dos formas distintas de dato. La marca es un juego CERRADO
 * y conocido —cinco colores, dos fuentes, un logo, un nombre—, cada uno con
 * su regla («esto es un hexadecimal de seis dígitos») y con un formulario
 * del panel que lo va a validar campo a campo; en columnas, la base ayuda a
 * comprobarlo y una consulta puede buscar por él. La lista de módulos es lo
 * contrario: un catálogo ORDENADO y en crecimiento —hay catorce planeados,
 * y ninguno construido salvo Menús—, donde lo que cambia no es el valor sino
 * la propia lista. Una columna por módulo significaría una migración por
 * módulo, que es exactamente lo que la promesa del white-label dice que no
 * puede pasar. Y los textos son un diccionario abierto por el mismo motivo.
 *
 * TODAS LAS COLUMNAS DE MARCA SON NULAS Y SIN DEFAULT EN LA BASE, a
 * conciencia. El valor por defecto vive en el modelo y en un solo sitio: un
 * default en la base y otro en el código se separan el día que alguien
 * cambia uno, y entonces un evento configurado y otro sin configurar dejan
 * de parecerse sin que nadie lo haya decidido. Nulo aquí significa «no lo ha
 * tocado nadie», que es una información distinta de «lo puso igual al
 * defecto».
 *
 * No lleva vendor_id, y es la excepción razonada a la costumbre de las
 * tablas nuevas del mundo evento: el manifiesto es del EVENTO entero, ningún
 * comercio lo posee y ninguna consulta con comercio en contexto lo lee. El
 * modelo no usa BelongsToVendor, así que VendorScope —que falla ABIERTO— ni
 * siquiera llega a mirarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_app_manifests')) {
            return;
        }

        Schema::create('event_app_manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // La marca. Nombre y logo primero, que es lo que se ve antes de
            // que la app pinte nada.
            $table->string('app_name')->nullable();
            $table->string('logo_path')->nullable();

            // Siete caracteres: «#» más seis dígitos hexadecimales. La app
            // no sabe leer otra cosa y el contrato lo fija.
            $table->string('primary_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->string('background_color', 7)->nullable();
            $table->string('surface_color', 7)->nullable();
            $table->string('text_color', 7)->nullable();

            $table->string('heading_font')->nullable();
            $table->string('body_font')->nullable();

            $table->json('modules')->nullable();
            $table->json('texts')->nullable();

            $table->timestamps();

            // Un manifiesto por evento. tenant_id va DELANTE porque
            // SchemaConventionTest lo exige: un upsert de una cuenta no
            // puede resolver su conflicto contra la fila de otra.
            $table->unique(['tenant_id', 'event_id'], 'event_app_manifests_one_per_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_app_manifests');
    }
};
