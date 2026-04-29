<?php

namespace App\Enums;

enum Permission: string
{
    case ManagePlatform = 'platform.manage';
    case ManageTenant = 'tenant.manage';
    case ManageTenantUsers = 'tenant_users.manage';
    case ManageAgentConfig = 'agent_configs.manage';
    case ViewConversations = 'conversations.view';
    case ReplyToConversations = 'conversations.reply';
    case ManageHandoffs = 'handoffs.manage';
    case ViewCredentialMetadata = 'credentials.view_metadata';
}
