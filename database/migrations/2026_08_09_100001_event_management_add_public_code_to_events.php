<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El código con el que la app del asistente dice de qué evento es. Viaja
 * COMPILADO en el binario (`--dart-define EVENTO=...`), uno por flavor, así
 * que no es un secreto: cualquiera que instale la app lo lleva encima. Lo
 * único que hace es elegir evento; no autoriza nada, porque detrás no hay
 * nada que escribir.
 *
 * El índice único es GLOBAL, y es la SEGUNDA excepción a la regla de
 * componer los únicos con tenant_id —la primera es vendors.kds_code—. Tiene
 * que serlo por el mismo motivo: un teléfono recién descargado pregunta por
 * un código sin saber a qué cuenta pertenece el festival, así que la
 * resolución es forzosamente cross-tenant y un único por cuenta permitiría
 * que dos organizadores repartieran el mismo código y ninguna app supiera
 * cuál de los dos es el suyo. La excepción se argumenta por su nombre en
 * SchemaConventionTest.
 *
 * El alfabeto se repite aquí a mano en vez de llamar a IssueEventPublicCode:
 * una migración es historia y no puede depender de que una clase de la app
 * siga existiendo —ni comportándose igual— dentro de un año. Es la misma
 * decisión, y por el mismo motivo, que la migración del código del KDS.
 */
return new class extends Migration
{
    /** Sin O/0 ni I/1/l: el código se dicta y se teclea también a mano. */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LARGO = 8;

    /** Trozo del relleno: ni una consulta por evento ni todos en memoria. */
    private const LOTE = 500;

    public function up(): void
    {
        if (! Schema::hasColumn('events', 'public_code')) {
            Schema::table('events', function (Blueprint $table): void {
                // Holgado (16) y no 8: la columna admite además un código de
                // vanidad puesto a mano —«BOCAO26» en el cartel—, que es más
                // largo que el generado y no usa este alfabeto.
                $table->string('public_code', 16)->nullable()->after('status');
            });
        }

        // Primero el relleno y después el índice: los eventos que ya existen
        // entran con código, y si algo colisionara queremos que reviente
        // aquí y no al crear el índice sobre datos a medias.
        $this->rellenarLosEventosExistentes();

        if (! $this->tieneIndice('events_public_code_unique_global')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->unique('public_code', 'events_public_code_unique_global');
            });
        }
    }

    public function down(): void
    {
        if ($this->tieneIndice('events_public_code_unique_global')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->dropUnique('events_public_code_unique_global');
            });
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('public_code');
        });
    }

    /**
     * Sin el modelo a propósito: TenantScope falla cerrado y una migración
     * corre sin cuenta activa, así que Event::query() no vería ni una fila.
     *
     * SE PAGINA POR ID Y NO CON each(), Y ESA ES LA DIFERENCIA ENTRE RELLENAR
     * TODO Y RELLENAR LA MITAD. `each()` pagina por OFFSET sobre una consulta
     * cuyo propio filtro —`whereNull('public_code')`— es justo lo que el bucle
     * va borrando: con mil quinientos eventos, la primera página rellena mil,
     * la segunda pide «desde la fila número mil» de las que TODAVÍA están sin
     * código —que ya son solo quinientas— y vuelve vacía. El bucle termina
     * convencido, la migración pasa (el único global admite tantos NULL como
     * quiera) y quinientos eventos se quedan sin código sin que nada lo diga,
     * hasta que la app de uno de ellos no arranca en el festival. Con el
     * cursor en el id, lo que se lleva un trozo no puede mover a los
     * siguientes.
     */
    private function rellenarLosEventosExistentes(): void
    {
        // Mapa y no lista: con miles de eventos, un in_array por cada intento
        // convierte el relleno en cuadrático sin ganar nada.
        $usados = [];

        foreach (DB::table('events')->whereNotNull('public_code')->pluck('public_code') as $codigo) {
            $usados[(string) $codigo] = true;
        }

        DB::table('events')->whereNull('public_code')
            ->chunkById(self::LOTE, function ($eventos) use (&$usados): void {
                foreach ($eventos as $evento) {
                    do {
                        $codigo = $this->codigo();
                    } while (isset($usados[$codigo]));

                    $usados[$codigo] = true;

                    DB::table('events')->where('id', $evento->id)->update(['public_code' => $codigo]);
                }
            });
    }

    private function codigo(): string
    {
        $codigo = '';

        for ($i = 0; $i < self::LARGO; $i++) {
            $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $codigo;
    }

    private function tieneIndice(string $nombre): bool
    {
        return collect(Schema::getIndexes('events'))
            ->contains(fn (array $indice): bool => $indice['name'] === $nombre);
    }
};
