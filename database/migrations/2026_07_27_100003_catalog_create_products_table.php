<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories');
            $table->string('name');
            $table->string('type');
            $table->unsignedBigInteger('price_cents');
            $table->boolean('track_stock')->default(false);
            // Solo para productos sencillos con inventario: el ítem que
            // consumen 1:1 (una cerveza descuenta una cerveza).
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
