<?php

namespace App\Domain\WhatsApp;

use App\Domain\WhatsApp\Contracts\WhatsAppLineResolver;
use App\Domain\WhatsApp\DTO\ResolvedWhatsAppLine;
use App\Domain\WhatsApp\Exceptions\WhatsAppLineNotFoundException;
use App\Models\WhatsAppLine;

class DatabaseWhatsAppLineResolver implements WhatsAppLineResolver
{
    public function resolve(string $phoneNumberId): ResolvedWhatsAppLine
    {
        $line = WhatsAppLine::query()
            ->with('tenant')
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if (! $line || ! $line->tenant) {
            throw new WhatsAppLineNotFoundException(
                'whatsapp_line_not_found',
                'api.webhook.line_not_found',
                ['phone_number_id' => $phoneNumberId],
                404,
            );
        }

        return new ResolvedWhatsAppLine($line, $line->tenant);
    }
}
