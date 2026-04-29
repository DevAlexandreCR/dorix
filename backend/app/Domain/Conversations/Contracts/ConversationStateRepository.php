<?php

namespace App\Domain\Conversations\Contracts;

use App\Models\Conversation;
use App\Models\ConversationState;
use Carbon\CarbonInterface;

interface ConversationStateRepository
{
    public function getOrCreate(Conversation $conversation): ConversationState;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Conversation $conversation, array $attributes): ConversationState;

    public function expireOperationalMemory(ConversationState $state, ?CarbonInterface $now = null): ConversationState;
}
