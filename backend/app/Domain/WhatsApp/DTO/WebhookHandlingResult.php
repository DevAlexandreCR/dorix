<?php

namespace App\Domain\WhatsApp\DTO;

final readonly class WebhookHandlingResult
{
    public function __construct(
        public int $receivedMessages = 0,
        public int $deduplicatedMessages = 0,
        public int $statusUpdates = 0,
        public int $jobsDispatched = 0,
    ) {
    }
}
