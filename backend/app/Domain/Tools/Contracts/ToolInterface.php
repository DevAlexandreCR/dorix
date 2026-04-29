<?php

namespace App\Domain\Tools\Contracts;

use App\Domain\Tools\DTO\ToolDefinition;
use App\Domain\Tools\DTO\ToolInvocation;
use App\Domain\Tools\DTO\ToolResult;

interface ToolInterface
{
    public function definition(): ToolDefinition;

    public function execute(ToolInvocation $invocation): ToolResult;
}
