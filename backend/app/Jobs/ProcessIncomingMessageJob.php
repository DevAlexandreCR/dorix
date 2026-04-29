<?php

namespace App\Jobs;

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

    public function handle(TenantContextManager $manager, AgentEventRecorder $events): void
    {
        $this->runInTenantContext($manager, $this->tenantId, function () use ($events): void {
            $events->record($this->tenantId, 'processing_job_started', [
                'conversation_id' => $this->conversationId,
                'conversation_message_id' => $this->messageId,
                'payload' => [
                    'job' => static::class,
                ],
            ]);

            $events->record($this->tenantId, 'processing_job_completed', [
                'conversation_id' => $this->conversationId,
                'conversation_message_id' => $this->messageId,
                'payload' => [
                    'job' => static::class,
                ],
            ]);
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
