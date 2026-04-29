<?php

namespace App\Domain\Conversations\Contracts;

interface ConversationLockManager
{
    /**
     * @param  callable(): void  $callback
     */
    public function runExclusive(int $tenantId, int $conversationId, callable $callback): bool;
}
