<?php

namespace App\Domain\Conversations;

use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Models\Conversation;
use App\Models\ConversationState;
use Carbon\CarbonInterface;

class EloquentConversationStateRepository implements ConversationStateRepository
{
    public function getOrCreate(Conversation $conversation): ConversationState
    {
        return ConversationState::query()->firstOrCreate([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->getKey(),
        ]);
    }

    public function update(Conversation $conversation, array $attributes): ConversationState
    {
        $state = $this->getOrCreate($conversation);

        $state->forceFill($attributes)->save();

        return $state->fresh();
    }

    public function expireOperationalMemory(ConversationState $state, ?CarbonInterface $now = null): ConversationState
    {
        $now ??= now();

        if (! $state->expires_at || $state->expires_at->isFuture()) {
            return $state;
        }

        if ($state->memory_summary === null) {
            return $state;
        }

        $state->forceFill([
            'memory_summary' => null,
            'expires_at' => null,
        ])->save();

        return $state->fresh();
    }
}
