<?php

namespace App\Domain\DataSources;

use App\Domain\DataSources\Exceptions\DataSourceUnavailableException;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Models\DataSource;
use App\Support\AgentEvents\AgentEventRecorder;

class ToolBoundDataSourceResolver
{
    public function __construct(
        protected AgentEventRecorder $events,
    ) {}

    public function resolveForTool(ToolInvocation $invocation): DataSource
    {
        $tenantId = $invocation->context->tenant->getKey();
        $bindingId = $invocation->tool->config->bindings['data_source_id'] ?? null;

        if (is_numeric($bindingId)) {
            $boundSource = DataSource::query()
                ->forTenant($tenantId)
                ->whereKey((int) $bindingId)
                ->first();

            if (! $boundSource) {
                throw new DataSourceUnavailableException(__('api.data_sources.import.line_binding_missing', [
                    'tool_name' => $invocation->tool->name(),
                ]));
            }

            if ($boundSource->status !== 'ready') {
                throw new DataSourceUnavailableException(__('api.data_sources.import.tenant_binding_missing', [
                    'tool_name' => $invocation->tool->name(),
                ]));
            }

            $this->recordResolutionEvent($invocation, $boundSource, 'binding');

            return $boundSource;
        }

        $fallback = DataSource::query()
            ->forTenant($tenantId)
            ->whereIn('type', ['excel', 'xlsx', 'csv', 'txt', 'pdf'])
            ->where('status', 'ready')
            ->orderByDesc('last_synced_at')
            ->orderByDesc('id')
            ->first();

        if (! $fallback) {
            throw new DataSourceUnavailableException(__('api.data_sources.import.fallback_missing', [
                'tool_name' => $invocation->tool->name(),
            ]));
        }

        $this->recordResolutionEvent($invocation, $fallback, 'fallback');

        return $fallback;
    }

    protected function recordResolutionEvent(ToolInvocation $invocation, DataSource $dataSource, string $strategy): void
    {
        $this->events->record($invocation->context->tenant->getKey(), 'tool_data_source_resolved', [
            'whatsapp_line_id' => $invocation->context->line->getKey(),
            'conversation_id' => $invocation->context->conversation->getKey(),
            'conversation_message_id' => $invocation->context->triggeringMessage->getKey(),
            'payload' => [
                'tool_name' => $invocation->tool->name(),
                'data_source_id' => $dataSource->getKey(),
                'strategy' => $strategy,
                'status' => $dataSource->status,
                'last_import_id' => $dataSource->metadata['last_import_id'] ?? null,
            ],
        ]);
    }
}
