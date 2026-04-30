<?php

namespace App\Enums;

enum ConversationSource: string
{
    case WhatsApp = 'whatsapp';
    case AgentSandbox = 'agent_sandbox';
}
