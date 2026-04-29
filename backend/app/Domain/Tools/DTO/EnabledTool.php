<?php

namespace App\Domain\Tools\DTO;

use App\Models\TenantToolConfig;

final readonly class EnabledTool
{
    public function __construct(
        public ToolDefinition $definition,
        public TenantToolConfig $config,
    ) {
    }

    public function name(): string
    {
        return $this->definition->name;
    }

    public function timeoutSeconds(): int
    {
        return max(1, (int) ($this->config->timeout_seconds ?? $this->definition->defaultTimeoutSeconds));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->definition->toArray(), [
            'enabled' => $this->config->enabled,
            'timeout_seconds' => $this->timeoutSeconds(),
            'overrides' => $this->config->overrides ?? [],
            'bindings' => $this->config->bindings ?? [],
            'scope_key' => $this->config->scope_key,
        ]);
    }
}
