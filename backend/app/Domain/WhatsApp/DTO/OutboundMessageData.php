<?php

namespace App\Domain\WhatsApp\DTO;

final readonly class OutboundMessageData
{
    public function __construct(
        public int $tenantId,
        public int $conversationId,
        public int $whatsAppLineId,
        public string $recipientPhone,
        public string $body,
        public string $idempotencyKey,
        public array $payload = [],
        public string $messageType = 'text',
    ) {
    }
}
