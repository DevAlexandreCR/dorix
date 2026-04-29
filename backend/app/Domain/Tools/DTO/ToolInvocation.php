<?php

namespace App\Domain\Tools\DTO;

use App\Domain\Agent\DTO\AgentContext;

final readonly class ToolInvocation
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public AgentContext $context,
        public EnabledTool $tool,
        public array $arguments,
    ) {
    }
}
