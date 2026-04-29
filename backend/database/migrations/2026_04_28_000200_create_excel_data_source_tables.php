<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_source_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_file_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('processed_sheet_count')->default(0);
            $table->unsignedInteger('generated_chunk_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['data_source_id', 'created_at']);
        });

        Schema::create('data_source_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_import_id')->constrained()->cascadeOnDelete();
            $table->string('chunk_type');
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('row_start')->nullable();
            $table->unsignedInteger('row_end')->nullable();
            $table->string('section_key')->nullable();
            $table->text('content_text');
            $table->json('structured_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'data_source_id']);
            $table->index(['tenant_id', 'data_source_import_id']);
            $table->index(['tenant_id', 'chunk_type']);
            $table->fullText('content_text');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_chunks');
        Schema::dropIfExists('data_source_imports');
    }
};
