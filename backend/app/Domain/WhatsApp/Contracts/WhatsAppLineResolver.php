<?php

namespace App\Domain\WhatsApp\Contracts;

use App\Domain\WhatsApp\DTO\ResolvedWhatsAppLine;

interface WhatsAppLineResolver
{
    public function resolve(string $phoneNumberId): ResolvedWhatsAppLine;
}
