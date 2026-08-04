<?php

namespace App\Domain\Scheduling;

use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;

/**
 * Resolves the scheduling configuration (calendar id, business hours,
 * timezone) for a line from `tenant_tool_configs.overrides`, with the same
 * line->tenant fallback every other tool config resolution in this codebase
 * uses (see {@see \App\Domain\Agent\AgentContextLoader::resolveAgentConfigs()}
 * and {@see \App\Domain\Agent\AgentContextLoader::resolveEnabledTools()}):
 * a line-scoped row wins over a tenant-scoped row when both exist; there is
 * no field-by-field merge of `overrides` between scopes.
 *
 * Config lives under a single, fixed tool_name ({@see self::CONFIG_TOOL_NAME})
 * rather than one copy per scheduling tool. Both `check_availability` (task
 * 4.3) and `create_appointment` (task 4.4) MUST resolve through this same
 * resolver/tool_name so the calendar and business hours are a single source
 * of truth instead of two independently-editable copies that could drift
 * apart (e.g. availability computed against one calendar, appointments
 * inserted into another).
 */
class SchedulingConfigResolver
{
    public const CONFIG_TOOL_NAME = 'check_availability';

    public function forLine(WhatsAppLine $line): SchedulingConfiguration
    {
        $lineScopeKey = TenantScopeKey::forWhatsAppLine($line);
        $tenantScopeKey = TenantScopeKey::forTenant($line->tenant_id);

        $config = TenantToolConfig::query()
            ->forTenant($line->tenant_id)
            ->where('tool_name', self::CONFIG_TOOL_NAME)
            ->whereIn('scope_key', [$lineScopeKey, $tenantScopeKey])
            ->orderByRaw('case when scope_key = ? then 0 else 1 end', [$lineScopeKey])
            ->first();

        return SchedulingConfiguration::fromOverrides($config?->overrides ?? []);
    }
}
