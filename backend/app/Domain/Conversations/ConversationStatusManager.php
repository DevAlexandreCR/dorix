<?php

namespace App\Domain\Conversations;

use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\Conversations\Exceptions\InvalidConversationStatusTransitionException;
use App\Enums\ConversationStatus;
use App\Models\Conversation;

class ConversationStatusManager implements ConversationStatusTransitioner
{
    /**
     * @var array<string, array<int, ConversationStatus>>
     */
    protected array $allowedTransitions = [
        ConversationStatus::BotActive->value => [
            ConversationStatus::BotActive,
            ConversationStatus::WaitingCustomer,
            ConversationStatus::HumanHandoff,
            ConversationStatus::Closed,
            ConversationStatus::Error,
        ],
        ConversationStatus::WaitingCustomer->value => [
            ConversationStatus::WaitingCustomer,
            ConversationStatus::BotActive,
            ConversationStatus::HumanHandoff,
            ConversationStatus::Closed,
            ConversationStatus::Error,
        ],
        ConversationStatus::HumanHandoff->value => [
            ConversationStatus::HumanHandoff,
            ConversationStatus::BotActive,
            ConversationStatus::WaitingCustomer,
            ConversationStatus::Closed,
            ConversationStatus::Error,
        ],
        ConversationStatus::Closed->value => [
            ConversationStatus::Closed,
            ConversationStatus::BotActive,
            ConversationStatus::HumanHandoff,
        ],
        ConversationStatus::Error->value => [
            ConversationStatus::Error,
            ConversationStatus::BotActive,
            ConversationStatus::HumanHandoff,
            ConversationStatus::Closed,
        ],
    ];

    public function transition(
        Conversation $conversation,
        ConversationStatus $targetStatus,
        ?int $assignedToUserId = null,
    ): Conversation {
        $currentStatus = $conversation->status;

        if (! in_array($targetStatus, $this->allowedTransitions[$currentStatus->value] ?? [], true)) {
            throw InvalidConversationStatusTransitionException::make($currentStatus, $targetStatus);
        }

        $conversation->forceFill([
            'status' => $targetStatus,
            'assigned_to_user_id' => $targetStatus === ConversationStatus::HumanHandoff
                ? ($assignedToUserId ?? $conversation->assigned_to_user_id)
                : null,
        ])->save();

        return $conversation->fresh();
    }
}
