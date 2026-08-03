<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El código que se teclea en la tablet la primera vez. Identifica al
 * comercio, es público por diseño (lo lleva escrito el encargado en un
 * papel) y por sí solo no abre nada: el que autentica es el PIN del puesto.
 *
 * El índice único es GLOBAL, no compuesto con tenant_id, y es la única
 * excepción a esa regla en toda la base. Tiene que serlo: una tablet recién
 * sacada de la caja teclea ocho caracteres sin saber a qué cuenta pertenece
 * su comercio, así que la resolución es forzosamente cross-tenant y un
 * único por cuenta permitiría que dos organizadores repartieran el mismo
 * código. La excepción está argumentada por su nombre en
 * SchemaConventionTest, donde vive el porqué completo.
 *
 * El alfabeto se repite aquí a mano en vez de llamar a IssueVendorKdsCode:
 * una migración es historia y no puede depender de que una clase de la app
 * siga existiendo —ni comportándose igual— dentro de un año.
 */
return new class extends Migration
{
    /** Sin O/0 ni I/1/l: el código se dicta por teléfono y se teclea a dedo. */
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'kds_code')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->string('kds_code', 8)->nullable()->after('status');
            });
        }

        // Primero el relleno y después el índice: los comercios que ya
        // existen entran con código, y si algo colisionara queremos que
        // reviente aquí y no al crear el índice sobre datos a medias.
        $this->rellenarLosComerciosExistentes();

        if (! $this->tieneIndice('vendors_kds_code_unique_global')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->unique('kds_code', 'vendors_kds_code_unique_global');
            });
        }
    }

    public function down(): void
    {
        if ($this->tieneIndice('vendors_kds_code_unique_global')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->dropUnique('vendors_kds_code_unique_global');
            });
        }

        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropColumn('kds_code');
        });
    }

    /**
     * Sin el modelo a propósito: TenantScope falla cerrado y una migración
     * corre sin cuenta activa, así que Vendor::query() no vería ni una fila.
     */
    private function rellenarLosComerciosExistentes(): void
    {
        /** @var array<int, string> $usados */
        $usados = DB::table('vendors')->whereNotNull('kds_code')->pluck('kds_code')->all();

        DB::table('vendors')->whereNull('kds_code')->orderBy('id')
            ->each(function (object $vendor) use (&$usados): void {
                do {
                    $codigo = $this->codigo();
                } while (in_array($codigo, $usados, true));

                $usados[] = $codigo;

                DB::table('vendors')->where('id', $vendor->id)->update(['kds_code' => $codigo]);
            });
    }

    private function codigo(): string
    {
        $codigo = '';

        for ($i = 0; $i < 8; $i++) {
            $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return $codigo;
    }

    private function tieneIndice(string $nombre): bool
    {
        return collect(Schema::getIndexes('vendors'))
            ->contains(fn (array $indice): bool => $indice['name'] === $nombre);
    }
};
