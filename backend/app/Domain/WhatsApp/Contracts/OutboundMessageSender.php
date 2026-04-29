<?php

namespace App\Domain\WhatsApp\Contracts;

use App\Domain\WhatsApp\DTO\OutboundMessageData;
use App\Models\ConversationMessage;

interface OutboundMessageSender
{
    public function send(OutboundMessageData $outboundMessage): ConversationMessage;
}
