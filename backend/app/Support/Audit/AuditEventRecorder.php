<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class AuditEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        int $tenantId,
        string $eventType,
        ?int $actorUserId = null,
        ?Model $target = null,
        array $payload = [],
        ?CarbonInterface $occurredAt = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'payload' => $payload,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
