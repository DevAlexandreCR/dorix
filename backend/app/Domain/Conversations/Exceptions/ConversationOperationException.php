<?php

namespace App\Domain\Conversations\Exceptions;

use RuntimeException;

class ConversationOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
