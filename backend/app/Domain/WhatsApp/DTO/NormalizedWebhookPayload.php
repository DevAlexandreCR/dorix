<?php

namespace App\Domain\WhatsApp\DTO;

final readonly class NormalizedWebhookPayload
{
    /**
     * @param  array<int, InboundMessageData>  $inboundMessages
     * @param  array<int, StatusUpdateData>  $statusUpdates
     */
    public function __construct(
        public array $inboundMessages,
        public array $statusUpdates,
    ) {
    }
}
