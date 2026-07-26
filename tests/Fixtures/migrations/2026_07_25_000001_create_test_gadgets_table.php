<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the Gadget fixture. Mirrors the schema conventions every business
 * table must follow: tenant_id NOT NULL with a foreign key, and unique
 * indexes composed with tenant_id so an upsert can never resolve a
 * conflict against another tenant's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_gadgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_gadgets');
    }
};
