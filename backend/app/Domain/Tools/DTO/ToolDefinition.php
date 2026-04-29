<?php

namespace App\Domain\Tools\DTO;

final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
        public int $defaultTimeoutSeconds = 10,
        public bool $isImplemented = true,
        public int $implementationPhase = 5,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
            'output_schema' => $this->outputSchema,
            'default_timeout_seconds' => $this->defaultTimeoutSeconds,
            'is_implemented' => $this->isImplemented,
            'implementation_phase' => $this->implementationPhase,
        ];
    }
}
