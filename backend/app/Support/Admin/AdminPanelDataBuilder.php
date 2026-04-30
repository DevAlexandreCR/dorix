<?php

namespace App\Support\Admin;

use App\Domain\Tools\ToolRegistry;
use App\Models\AgentConfig;
use App\Models\AgentEvent;
use App\Models\ApiCredential;
use App\Models\AuditEvent;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\TenantUser;
use App\Models\ToolExecution;
use App\Models\WhatsAppLine;
use App\Support\Observability\ObservabilityPayloadSanitizer;
use Illuminate\Support\Collection;

class AdminPanelDataBuilder
{
    public function __construct(
        protected ToolRegistry $toolRegistry,
        protected ObservabilityPayloadSanitizer $sanitizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Tenant $tenant): array
    {
        return [
            'tenant' => $this->serializeTenant($tenant->fresh()),
            'tenant_users' => TenantUser::query()
                ->forTenant($tenant->getKey())
                ->with('user:id,name,email')
                ->orderBy('role')
                ->orderBy('id')
                ->get()
                ->map(fn (TenantUser $membership): array => $this->serializeTenantUser($membership))
                ->all(),
            'whatsapp_lines' => WhatsAppLine::query()
                ->forTenant($tenant->getKey())
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (WhatsAppLine $line): array => $this->serializeWhatsAppLine($line))
                ->all(),
            'credential_metadata' => ApiCredential::query()
                ->forTenant($tenant->getKey())
                ->with('whatsappLine:id,name,display_phone_number')
                ->orderBy('provider')
                ->orderBy('credential_key')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ApiCredential $credential): array => $this->serializeCredentialMetadata($credential))
                ->all(),
            'agent_configs' => AgentConfig::query()
                ->forTenant($tenant->getKey())
                ->with('whatsappLine:id,name,display_phone_number')
                ->orderBy('scope_type')
                ->orderBy('id')
                ->get()
                ->map(fn (AgentConfig $config): array => $this->serializeAgentConfig($config))
                ->all(),
            'tool_configs' => TenantToolConfig::query()
                ->forTenant($tenant->getKey())
                ->with('whatsappLine:id,name,display_phone_number')
                ->orderBy('scope_type')
                ->orderBy('tool_name')
                ->orderBy('id')
                ->get()
                ->map(fn (TenantToolConfig $config): array => $this->serializeToolConfig($config))
                ->all(),
            'data_sources' => $this->dataSourcesForTenant($tenant->getKey())
                ->map(fn (DataSource $source): array => $this->serializeDataSource($source))
                ->all(),
            'logs' => [
                'agent_events' => AgentEvent::query()
                    ->forTenant($tenant->getKey())
                    ->latest('occurred_at')
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (AgentEvent $event): array => $this->serializeAgentEvent($event))
                    ->all(),
                'audit_events' => AuditEvent::query()
                    ->forTenant($tenant->getKey())
                    ->with('actorUser:id,name,email')
                    ->latest('occurred_at')
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (AuditEvent $event): array => $this->serializeAuditEvent($event))
                    ->all(),
                'tool_executions' => ToolExecution::query()
                    ->forTenant($tenant->getKey())
                    ->latest('executed_at')
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (ToolExecution $execution): array => $this->serializeToolExecution($execution))
                    ->all(),
            ],
            'available_roles' => [
                'tenant_admin',
                'operator',
                'viewer',
            ],
            'binding_tools' => [
                'search_inventory',
                'search_knowledge',
            ],
            'available_tools' => collect($this->toolRegistry->all())
                ->map(fn ($tool): array => [
                    'name' => $tool->definition()->name,
                    'description' => $tool->definition()->description,
                    'supports_data_source_binding' => in_array($tool->definition()->name, [
                        'search_inventory',
                        'search_knowledge',
                    ], true),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, DataSource>
     */
    public function dataSourcesForTenant(int $tenantId): Collection
    {
        return DataSource::query()
            ->forTenant($tenantId)
            ->with(['uploadedFiles' => fn ($query) => $query->latest('id')->limit(1)])
            ->with(['imports' => fn ($query) => $query->latest('id')->limit(1)])
            ->withCount(['chunks'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTenant(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'metadata' => $tenant->metadata ?? [],
            'created_at' => $tenant->created_at?->toIso8601String(),
            'updated_at' => $tenant->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTenantUser(TenantUser $membership): array
    {
        return [
            'id' => $membership->getKey(),
            'tenant_id' => $membership->tenant_id,
            'role' => $membership->role?->value,
            'created_at' => $membership->created_at?->toIso8601String(),
            'updated_at' => $membership->updated_at?->toIso8601String(),
            'user' => $membership->user ? [
                'id' => $membership->user->getKey(),
                'name' => $membership->user->name,
                'email' => $membership->user->email,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeWhatsAppLine(WhatsAppLine $line): array
    {
        return [
            'id' => $line->getKey(),
            'tenant_id' => $line->tenant_id,
            'name' => $line->name,
            'phone_number_id' => $line->phone_number_id,
            'display_phone_number' => $line->display_phone_number,
            'waba_id' => $line->waba_id,
            'status' => $line->status,
            'is_enabled' => $line->is_enabled,
            'metadata' => $line->metadata ?? [],
            'created_at' => $line->created_at?->toIso8601String(),
            'updated_at' => $line->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCredentialMetadata(ApiCredential $credential): array
    {
        return [
            'id' => $credential->getKey(),
            'tenant_id' => $credential->tenant_id,
            'whatsapp_line_id' => $credential->whatsapp_line_id,
            'scope_type' => $credential->scope_type,
            'scope_key' => $credential->scope_key,
            'provider' => $credential->provider,
            'credential_key' => $credential->credential_key,
            'metadata' => $credential->metadata ?? [],
            'last_used_at' => $credential->last_used_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
            'has_secret' => filled($credential->getRawOriginal('secret')),
            'whatsapp_line' => $credential->whatsappLine ? [
                'id' => $credential->whatsappLine->getKey(),
                'name' => $credential->whatsappLine->name,
                'display_phone_number' => $credential->whatsappLine->display_phone_number,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAgentConfig(AgentConfig $config): array
    {
        $settings = $config->settings ?? [];

        return [
            'id' => $config->getKey(),
            'tenant_id' => $config->tenant_id,
            'whatsapp_line_id' => $config->whatsapp_line_id,
            'scope_type' => $config->scope_type,
            'scope_key' => $config->scope_key,
            'name' => $config->name,
            'model' => $config->model,
            'prompt_version' => $config->prompt_version,
            'is_active' => $config->is_active,
            'settings' => $settings,
            'automation_enabled' => (bool) ($settings['automation_enabled'] ?? true),
            'system_prompt' => is_string($settings['system_prompt'] ?? null)
                ? $settings['system_prompt']
                : '',
            'created_at' => $config->created_at?->toIso8601String(),
            'updated_at' => $config->updated_at?->toIso8601String(),
            'whatsapp_line' => $config->whatsappLine ? [
                'id' => $config->whatsappLine->getKey(),
                'name' => $config->whatsappLine->name,
                'display_phone_number' => $config->whatsappLine->display_phone_number,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeToolConfig(TenantToolConfig $config): array
    {
        $bindings = $config->bindings ?? [];

        return [
            'id' => $config->getKey(),
            'tenant_id' => $config->tenant_id,
            'whatsapp_line_id' => $config->whatsapp_line_id,
            'scope_type' => $config->scope_type,
            'scope_key' => $config->scope_key,
            'tool_name' => $config->tool_name,
            'enabled' => $config->enabled,
            'timeout_seconds' => $config->timeout_seconds,
            'bindings' => $bindings,
            'data_source_id' => isset($bindings['data_source_id']) && is_numeric($bindings['data_source_id'])
                ? (int) $bindings['data_source_id']
                : null,
            'overrides' => $config->overrides ?? [],
            'created_at' => $config->created_at?->toIso8601String(),
            'updated_at' => $config->updated_at?->toIso8601String(),
            'whatsapp_line' => $config->whatsappLine ? [
                'id' => $config->whatsappLine->getKey(),
                'name' => $config->whatsappLine->name,
                'display_phone_number' => $config->whatsappLine->display_phone_number,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeDataSource(DataSource $dataSource): array
    {
        $latestImport = $dataSource->imports->first();
        $latestFile = $dataSource->uploadedFiles->first();

        return [
            'id' => $dataSource->getKey(),
            'name' => $dataSource->name,
            'type' => $dataSource->type,
            'status' => $dataSource->status,
            'config' => $dataSource->config ?? [],
            'metadata' => $dataSource->metadata ?? [],
            'last_synced_at' => $dataSource->last_synced_at?->toIso8601String(),
            'chunk_count' => $dataSource->chunks_count ?? 0,
            'latest_upload' => $latestFile ? [
                'id' => $latestFile->getKey(),
                'original_name' => $latestFile->original_name,
                'mime_type' => $latestFile->mime_type,
                'size_bytes' => $latestFile->size_bytes,
                'checksum' => $latestFile->checksum,
                'uploaded_by_user_id' => $latestFile->uploaded_by_user_id,
                'created_at' => $latestFile->created_at?->toIso8601String(),
            ] : null,
            'latest_import' => $latestImport ? [
                'id' => $latestImport->getKey(),
                'status' => $latestImport->status,
                'attempts_count' => $latestImport->attempts_count,
                'processed_sheet_count' => $latestImport->processed_sheet_count,
                'generated_chunk_count' => $latestImport->generated_chunk_count,
                'error_message' => $latestImport->error_message,
                'started_at' => $latestImport->started_at?->toIso8601String(),
                'finished_at' => $latestImport->finished_at?->toIso8601String(),
                'metadata' => $latestImport->metadata ?? [],
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAgentEvent(AgentEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'event_type' => $event->event_type,
            'conversation_id' => $event->conversation_id,
            'conversation_message_id' => $event->conversation_message_id,
            'whatsapp_line_id' => $event->whatsapp_line_id,
            'payload' => $this->safePayload($event->payload),
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAuditEvent(AuditEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'event_type' => $event->event_type,
            'actor_user' => $event->actorUser ? [
                'id' => $event->actorUser->getKey(),
                'name' => $event->actorUser->name,
                'email' => $event->actorUser->email,
            ] : null,
            'target_type' => $event->target_type,
            'target_id' => $event->target_id,
            'payload' => $this->safePayload($event->payload),
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeToolExecution(ToolExecution $execution): array
    {
        return [
            'id' => $execution->getKey(),
            'tool_name' => $execution->tool_name,
            'status' => $execution->status,
            'conversation_id' => $execution->conversation_id,
            'conversation_message_id' => $execution->conversation_message_id,
            'duration_ms' => $execution->duration_ms,
            'error_message' => is_string($execution->error_message)
                ? $this->sanitizer->previewString($execution->error_message, 160)
                : $execution->error_message,
            'input_summary' => $this->safePayload($execution->input_summary),
            'output_summary' => $this->safePayload($execution->output_summary),
            'metadata' => $this->safePayload($execution->metadata),
            'executed_at' => $execution->executed_at?->toIso8601String(),
            'created_at' => $execution->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    protected function safePayload(?array $payload): array
    {
        $sanitized = $this->sanitizer->sanitizeForPresentation($payload ?? []);

        return is_array($sanitized) ? $sanitized : ['value' => $sanitized];
    }
}
