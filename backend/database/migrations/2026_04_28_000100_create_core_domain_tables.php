<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'role']);
        });

        Schema::create('whatsapp_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_number_id')->unique();
            $table->string('display_phone_number')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('status')->default('inactive');
            $table->boolean('is_enabled')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope_type');
            $table->string('scope_key');
            $table->string('provider');
            $table->string('credential_key');
            $table->text('secret');
            $table->json('metadata')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'scope_key', 'provider', 'credential_key'], 'api_credentials_scope_provider_unique');
            $table->index(['tenant_id', 'scope_type']);
        });

        Schema::create('agent_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope_type');
            $table->string('scope_key');
            $table->string('name');
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'scope_key'], 'agent_configs_scope_unique');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_phone');
            $table->string('contact_name')->nullable();
            $table->string('status')->default('BOT_ACTIVE');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contact_phone']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('message_type');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_message_id'], 'conversation_messages_tenant_provider_unique');
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('current_intent')->nullable();
            $table->json('collected_data')->nullable();
            $table->string('last_agent_action')->nullable();
            $table->text('memory_summary')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique('conversation_id');
        });

        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('status')->default('pending');
            $table->json('config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'data_source_id']);
        });

        Schema::create('tenant_tool_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope_type');
            $table->string('scope_key');
            $table->string('tool_name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->json('overrides')->nullable();
            $table->json('bindings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'scope_key', 'tool_name'], 'tenant_tool_configs_scope_tool_unique');
        });

        Schema::create('tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name');
            $table->string('status');
            $table->json('input_summary')->nullable();
            $table->json('output_summary')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tool_name', 'status']);
        });

        Schema::create('handoff_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_type')->default('runtime');
            $table->string('status')->default('requested');
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->nullableMorphs('target');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('handoff_requests');
        Schema::dropIfExists('tool_executions');
        Schema::dropIfExists('tenant_tool_configs');
        Schema::dropIfExists('uploaded_files');
        Schema::dropIfExists('data_sources');
        Schema::dropIfExists('conversation_states');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('agent_configs');
        Schema::dropIfExists('api_credentials');
        Schema::dropIfExists('whatsapp_lines');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
