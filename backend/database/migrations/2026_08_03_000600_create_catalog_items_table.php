<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('price_type');
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->decimal('price_min', 12, 2)->nullable();
            $table->decimal('price_max', 12, 2)->nullable();
            $table->string('currency', 3)->default('COP');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->foreignId('assessment_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'kind']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
