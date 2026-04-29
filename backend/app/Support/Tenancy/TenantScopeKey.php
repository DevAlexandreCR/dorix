<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\WhatsAppLine;

final class TenantScopeKey
{
    public static function forTenant(Tenant|int $tenant): string
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return "tenant:{$tenantId}";
    }

    public static function forWhatsAppLine(WhatsAppLine|int $line): string
    {
        $lineId = $line instanceof WhatsAppLine ? $line->getKey() : $line;

        return "whatsapp_line:{$lineId}";
    }
}
