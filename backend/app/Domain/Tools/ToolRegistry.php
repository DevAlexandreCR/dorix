<?php

namespace App\Domain\Tools;

use App\Domain\Tools\Contracts\ToolInterface;
use App\Domain\Tools\DTO\EnabledTool;
use App\Domain\Tools\Exceptions\ToolNotFoundException;
use App\Models\TenantToolConfig;

class ToolRegistry
{
    /**
     * @var array<string, ToolInterface>
     */
    protected array $tools = [];

    /**
     * @param  iterable<int, ToolInterface>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->definition()->name] = $tool;
        }
    }

    /**
     * @return array<int, ToolInterface>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->tools);
    }

    public function get(string $name): ToolInterface
    {
        if (! $this->has($name)) {
            throw new ToolNotFoundException(__('api.tools.not_registered', [
                'tool_name' => $name,
            ]));
        }

        return $this->tools[$name];
    }

    /**
     * @param  array<int, TenantToolConfig>  $configs
     * @return array<int, EnabledTool>
     */
    public function resolveEnabledTools(array $configs): array
    {
        $enabledTools = [];

        foreach ($configs as $config) {
            if (! $config->enabled || ! $this->has($config->tool_name)) {
                continue;
            }

            $enabledTools[] = new EnabledTool(
                definition: $this->get($config->tool_name)->definition(),
                config: $config,
            );
        }

        return $enabledTools;
    }
}
