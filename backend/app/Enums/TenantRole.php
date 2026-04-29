<?php

namespace App\Enums;

enum TenantRole: string
{
    case TenantAdmin = 'tenant_admin';
    case Operator = 'operator';
    case Viewer = 'viewer';
}
