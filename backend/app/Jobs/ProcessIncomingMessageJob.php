<?php

namespace App\Jobs;

use App\Domain\Conversations\Contracts\ConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Models\Conversation;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\Jobs\RunsInTenantContext;
use App\Support\Tenancy\Jobs\TenantAwareJob;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessIncomingMessageJob implements ShouldQueue, TenantAwareJob
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsInTenantContext;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 45];

    public int $lockReleaseSeconds = 5;

    public function __construct(
        protected int $tenantId,
        public int $conversationId,
        public int $messageId,
    ) {
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function handle(
        TenantContextManager $manager,
        AgentEventRecorder $events,
        ConversationLockManager $lockManager,
        ConversationStateRepository $stateRepository,
    ): void
    {
        $this->runInTenantContext($manager, $this->tenantId, function () use ($events, $lockManager, $stateRepository): void {
            $processed = $lockManager->runExclusive($this->tenantId, $this->conversationId, function () use ($events, $stateRepository): void {
                $conversation = Conversation::query()
                    ->forTenant($this->tenantId)
                    ->findOrFail($this->conversationId);

                $state = $stateRepository->getOrCreate($conversation);
                $state = $stateRepository->expireOperationalMemory($state);

                $payload = [
                    'job' => static::class,
                    'conversation_status' => $conversation->status->value,
                    'conversation_state_id' => $state->getKey(),
                ];

                $events->record($this->tenantId, 'processing_job_started', [
                    'conversation_id' => $this->conversationId,
                    'conversation_message_id' => $this->messageId,
                    'payload' => $payload,
                ]);

                $events->record($this->tenantId, 'processing_job_completed', [
                    'conversation_id' => $this->conversationId,
                    'conversation_message_id' => $this->messageId,
                    'payload' => $payload,
                ]);
            });

            if ($processed) {
                return;
            }

            $events->record($this->tenantId, 'processing_job_released_due_to_lock', [
                'conversation_id' => $this->conversationId,
                'conversation_message_id' => $this->messageId,
                'payload' => [
                    'job' => static::class,
                ],
            ]);

            $this->job?->release($this->lockReleaseSeconds);
        });
    }

    public function failed(Throwable $exception): void
    {
        app(AgentEventRecorder::class)->record($this->tenantId, 'processing_job_failed', [
            'conversation_id' => $this->conversationId,
            'conversation_message_id' => $this->messageId,
            'payload' => [
                'job' => static::class,
                'exception' => $exception->getMessage(),
            ],
        ]);
    }
}
