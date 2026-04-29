<?php

namespace App\Domain\Conversations\Contracts;

use App\Domain\WhatsApp\DTO\InboundMessageData;
use App\Domain\WhatsApp\DTO\ResolvedWhatsAppLine;
use App\Models\Conversation;

interface ConversationResolver
{
    public function resolveForInbound(ResolvedWhatsAppLine $resolvedLine, InboundMessageData $message): Conversation;
}
