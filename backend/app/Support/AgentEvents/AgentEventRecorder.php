<?php

namespace App\Support\AgentEvents;

use App\Models\AgentEvent;
use App\Support\Observability\ObservabilityPayloadSanitizer;
use Carbon\CarbonInterface;

class AgentEventRecorder
{
    public function __construct(
        protected ObservabilityPayloadSanitizer $sanitizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(int $tenantId, string $eventType, array $attributes = []): AgentEvent
    {
        $payload = $attributes['payload'] ?? null;

        return AgentEvent::query()->create([
            'tenant_id' => $tenantId,
            'whatsapp_line_id' => $attributes['whatsapp_line_id'] ?? null,
            'conversation_id' => $attributes['conversation_id'] ?? null,
            'conversation_message_id' => $attributes['conversation_message_id'] ?? null,
            'event_type' => $eventType,
            'payload' => $payload === null ? null : $this->sanitizer->sanitizeForStorage($payload),
            'occurred_at' => ($attributes['occurred_at'] ?? null) instanceof CarbonInterface
                ? $attributes['occurred_at']
                : now(),
        ]);
    }
}
