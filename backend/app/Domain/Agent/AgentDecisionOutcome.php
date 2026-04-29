<?php

namespace App\Domain\Agent;

enum AgentDecisionOutcome: string
{
    case SendMessage = 'send_message';
    case RequestMissingInformation = 'request_missing_information';
    case CallTool = 'call_tool';
    case WaitForCustomer = 'wait_for_customer';
    case RequestHandoff = 'request_handoff';
    case NoReply = 'no_reply';
    case Error = 'error';
}
