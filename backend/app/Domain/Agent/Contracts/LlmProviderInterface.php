<?php

namespace App\Domain\Agent\Contracts;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;

interface LlmProviderInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $prompt
     */
    public function generateDecision(AgentContext $context, array $prompt): AgentDecision;
}
