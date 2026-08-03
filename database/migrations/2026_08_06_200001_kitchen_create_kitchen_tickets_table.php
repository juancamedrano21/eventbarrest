<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El estado de una comanda en el tablero de un puesto: qué venta, por qué
 * área sale y en qué punto va.
 *
 * Esta tabla es deliberadamente MUTABLE, y vive al lado de un dominio que
 * es todo lo contrario. `orders` y `order_lines` son historia congelada
 * —el dinero cobrado no se reescribe— pero una comanda no es un hecho
 * contable: es la vida de un plato entre que se vende y sale por la
 * ventanilla. Se toca varias veces en tres minutos, y a veces se toca mal
 * y hay que volver atrás. Por eso la comanda es una fila APARTE que
 * referencia la orden, exactamente como `refunds`: la venta no se entera.
 *
 * No hay fila para el estado pendiente. La ausencia de fila ES el estado
 * pendiente: el tablero se lee como `orders LEFT JOIN kitchen_tickets`, así
 * que una venta sincronizada aparece en cocina por existir, sin observer
 * que la cree ni job que pueda fallar.
 *
 * vendor_id es NOT NULL aquí, cuando en `orders` y `refunds` es nullable.
 * Allí puede ser nulo porque una cuenta de negocio no tiene negocios
 * internos y el aislamiento por tenant ya basta. Aquí no: quien lee esta
 * tabla es una tablet enrolada, fuera de toda sesión de usuario, y
 * VendorScope falla ABIERTO — si no hay comercio en contexto, no añade
 * cláusula. Con la columna obligatoria, la consulta del tablero siempre
 * puede filtrar por ella y la base es el último backstop contra que un
 * comercio vea las comandas de su competidor en el mismo festival.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kitchen_tickets')) {
            return;
        }

        Schema::create('kitchen_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('operating_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('area', 10);

            // Sin default: el estado siempre lo pone una transición, y una
            // fila que nazca sin él debe reventar aquí, no colarse en gris.
            $table->string('status', 12);

            // Cuántas unidades salen por esta área. Es un contador para la
            // tarjeta, no una verdad contable: quien manda son las líneas.
            $table->unsignedSmallInteger('items_count');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();

            // Qué tablet tocó cada paso. Sin foreign key todavía: la tabla
            // kds_devices la crea otro agente después, y una comanda no
            // puede depender de que exista el dispositivo para escribirse.
            $table->unsignedBigInteger('started_by_device_id')->nullable();
            $table->unsignedBigInteger('ready_by_device_id')->nullable();

            $table->timestamps();

            // Una comanda por venta y área: la barra y la cocina de la misma
            // orden son dos tarjetas, en dos tableros distintos. tenant_id
            // va DELANTE porque SchemaConventionTest lo exige — un upsert de
            // una cuenta no puede resolver su conflicto contra otra.
            $table->unique(['tenant_id', 'order_id', 'area'], 'kitchen_tickets_one_per_order_area');

            // El tablero: lo abierto de un puesto. El histórico: el mismo
            // puesto ordenado por hora, para la vista de las últimas.
            $table->index(['tenant_id', 'operating_unit_id', 'status'], 'kitchen_tickets_board_idx');
            $table->index(['tenant_id', 'operating_unit_id', 'created_at'], 'kitchen_tickets_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
    }
};
