<?php

namespace App\Domain\Agent\Contracts;

use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;

interface AgentRuntimeInterface
{
    public function run(AgentContext $context): AgentDecision;
}
