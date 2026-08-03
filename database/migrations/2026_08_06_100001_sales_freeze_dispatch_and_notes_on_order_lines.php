<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos datos que la línea vendida no guardaba y que la cocina necesita.
 *
 * `dispatch` (barra o cocina) vive hoy en categories.dispatch, que es
 * MUTABLE: recategorizar un producto en enero reescribiría qué comandas
 * fueron de cocina en diciembre. Como todo lo demás de una venta, se
 * congela al vender.
 *
 * `notes` es lo que el cliente pide y el cocinero tiene que leer: «sin
 * cebolla», «término medio». No es un campo de auditoría ni de precio —
 * es una instrucción de preparación, y por eso viaja con la línea y no
 * con la orden: sin cebolla es de ESE taco, no de todo el pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('dispatch', 10)->nullable()->after('product_name');
            $table->string('notes', 120)->nullable()->after('dispatch');
        });

        $this->rellenarElArea();
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn(['dispatch', 'notes']);
        });
    }

    /**
     * Las líneas ya vendidas heredan el área que su producto tiene HOY. Es
     * lo mejor disponible: nadie guardó la de entonces. Queda en null la
     * línea cuyo producto ya no exista, y el tablero documenta que ese
     * residuo cae al área que declare el puesto.
     */
    private function rellenarElArea(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'UPDATE order_lines ol '
                .'JOIN products p ON p.id = ol.product_id '
                .'JOIN categories c ON c.id = p.category_id '
                .'SET ol.dispatch = c.dispatch'
            );

            return;
        }

        DB::statement(
            'UPDATE order_lines SET dispatch = ('
            .'SELECT c.dispatch FROM products p '
            .'JOIN categories c ON c.id = p.category_id '
            .'WHERE p.id = order_lines.product_id)'
        );
    }
};
