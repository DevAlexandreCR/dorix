<?php

namespace App\Domain\Conversations;

use App\Domain\Conversations\Contracts\ConversationLockManager;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

class CacheConversationLockManager implements ConversationLockManager
{
    protected const DEFAULT_LOCK_SECONDS = 30;

    public function __construct(
        protected CacheFactory $cache,
    ) {
    }

    public function runExclusive(int $tenantId, int $conversationId, callable $callback): bool
    {
        $executed = false;

        $this->cache
            ->store(config('cache.default'))
            ->lock($this->lockKey($tenantId, $conversationId), self::DEFAULT_LOCK_SECONDS)
            ->get(function () use ($callback, &$executed): void {
                $executed = true;
                $callback();
            });

        return $executed;
    }

    protected function lockKey(int $tenantId, int $conversationId): string
    {
        return sprintf('conversations:%d:%d:processing', $tenantId, $conversationId);
    }
}
