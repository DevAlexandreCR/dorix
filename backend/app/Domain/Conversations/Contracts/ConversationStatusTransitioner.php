<?php

namespace App\Domain\Conversations\Contracts;

use App\Enums\ConversationStatus;
use App\Models\Conversation;

interface ConversationStatusTransitioner
{
    public function transition(
        Conversation $conversation,
        ConversationStatus $targetStatus,
        ?int $assignedToUserId = null,
    ): Conversation;
}
