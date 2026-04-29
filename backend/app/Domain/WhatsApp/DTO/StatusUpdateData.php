<?php

namespace App\Domain\WhatsApp\DTO;

use Carbon\CarbonImmutable;

final readonly class StatusUpdateData
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function __construct(
        public string $phoneNumberId,
        public string $providerMessageId,
        public string $status,
        public array $errors,
        public array $payload,
        public CarbonImmutable $occurredAt,
    ) {
    }
}
