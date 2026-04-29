<?php

namespace App\Domain\Tools;

enum ToolNextAction: string
{
    case SendMessageAndWait = 'send_message_and_wait';
    case WaitForCustomer = 'wait_for_customer';
    case RequestHandoff = 'request_handoff';
    case NoReply = 'no_reply';
}
