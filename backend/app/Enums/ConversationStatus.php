<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case BotActive = 'BOT_ACTIVE';
    case WaitingCustomer = 'WAITING_CUSTOMER';
    case HumanHandoff = 'HUMAN_HANDOFF';
    case Closed = 'CLOSED';
    case Error = 'ERROR';
}
