<?php

namespace App\Domain\WhatsApp\DTO;

use Carbon\CarbonImmutable;

/**
 * A `smb_message_echoes` webhook entry: a message the human sent to the
 * customer directly from the WhatsApp Business app while the line is in
 * coexistence mode. It is persisted as an outbound `ConversationMessage`
 * (design.md decision D6) — it never enters the inbound agent pipeline.
 */
final readonly class EchoMessageData
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
        public CarbonImmutable $sentAt,
    ) {
    }
}
