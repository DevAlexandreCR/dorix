<?php

namespace App\Domain\DataSources;

use App\Domain\DataSources\Exceptions\DataSourceUnavailableException;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Models\DataSource;

class ToolBoundDataSourceResolver
{
    public function resolveForTool(ToolInvocation $invocation): DataSource
    {
        $tenantId = $invocation->context->tenant->getKey();
        $bindingId = $invocation->tool->config->bindings['data_source_id'] ?? null;

        if (is_numeric($bindingId)) {
            $boundSource = DataSource::query()
                ->forTenant($tenantId)
                ->whereKey((int) $bindingId)
                ->first();

            if (! $boundSource) {
                throw new DataSourceUnavailableException(sprintf(
                    'The tool "%s" is bound to a data source that does not exist for the current tenant.',
                    $invocation->tool->name(),
                ));
            }

            if ($boundSource->status !== 'ready') {
                throw new DataSourceUnavailableException(sprintf(
                    'The bound knowledge source for tool "%s" is not ready yet.',
                    $invocation->tool->name(),
                ));
            }

            return $boundSource;
        }

        $fallback = DataSource::query()
            ->forTenant($tenantId)
            ->where('type', 'excel')
            ->where('status', 'ready')
            ->orderByDesc('last_synced_at')
            ->orderByDesc('id')
            ->first();

        if (! $fallback) {
            throw new DataSourceUnavailableException(sprintf(
                'No ready Excel knowledge source is available for tool "%s" in the current tenant.',
                $invocation->tool->name(),
            ));
        }

        return $fallback;
    }
}
