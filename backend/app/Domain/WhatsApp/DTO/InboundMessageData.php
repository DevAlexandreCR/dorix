<?php

namespace App\Domain\WhatsApp\DTO;

use Carbon\CarbonImmutable;

final readonly class InboundMessageData
{
    public function __construct(
        public string $phoneNumberId,
        public string $providerMessageId,
        public string $contactPhone,
        public ?string $contactName,
        public string $messageType,
        public ?string $body,
        public string $providerMessageType,
        public array $payload,
        public CarbonImmutable $receivedAt,
    ) {
    }
}
