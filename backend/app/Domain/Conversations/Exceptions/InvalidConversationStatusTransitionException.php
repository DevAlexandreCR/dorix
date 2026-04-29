<?php

namespace App\Domain\Conversations\Exceptions;

use App\Enums\ConversationStatus;
use RuntimeException;

class InvalidConversationStatusTransitionException extends RuntimeException
{
    public static function make(ConversationStatus $from, ConversationStatus $to): self
    {
        return new self(sprintf(
            'Conversation status transition from [%s] to [%s] is not allowed.',
            $from->value,
            $to->value,
        ));
    }
}
