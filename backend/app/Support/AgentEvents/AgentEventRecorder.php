<?php

namespace App\Support\AgentEvents;

use App\Models\AgentEvent;
use Carbon\CarbonInterface;

class AgentEventRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(int $tenantId, string $eventType, array $attributes = []): AgentEvent
    {
        return AgentEvent::query()->create([
            'tenant_id' => $tenantId,
            'whatsapp_line_id' => $attributes['whatsapp_line_id'] ?? null,
            'conversation_id' => $attributes['conversation_id'] ?? null,
            'conversation_message_id' => $attributes['conversation_message_id'] ?? null,
            'event_type' => $eventType,
            'payload' => $attributes['payload'] ?? null,
            'occurred_at' => ($attributes['occurred_at'] ?? null) instanceof CarbonInterface
                ? $attributes['occurred_at']
                : now(),
        ]);
    }
}
