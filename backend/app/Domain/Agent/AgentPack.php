<?php

namespace App\Domain\Agent;

use InvalidArgumentException;

final readonly class AgentPack
{
    /**
     * @param  array<string, IntentDefinition>  $intents
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $intents,
        public string $defaultHandoffMessageKey = 'api.agent.default_handoff_customer_message',
    ) {
    }

    public function intent(string $intentKey): IntentDefinition
    {
        $normalized = $this->normalizeIntent($intentKey);

        if (! $this->hasIntent($normalized)) {
            throw new InvalidArgumentException(sprintf('The intent "%s" is not registered in pack "%s".', $intentKey, $this->key));
        }

        return $this->intents[$normalized];
    }

    public function hasIntent(string $intentKey): bool
    {
        return array_key_exists($intentKey, $this->intents);
    }

    public function normalizeIntent(string $intentKey): string
    {
        $normalized = trim(strtolower($intentKey));
        $normalized = str_replace('-', '_', $normalized);

        if ($normalized !== '' && $this->hasIntent($normalized)) {
            return $normalized;
        }

        return 'unsupported';
    }

    /**
     * @return array<int, string>
     */
    public function intentKeys(): array
    {
        return array_keys($this->intents);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function intentsForPrompt(): array
    {
        return array_map(
            static fn (IntentDefinition $intent): array => $intent->toArray(),
            array_values($this->intents),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'default_handoff_message_key' => $this->defaultHandoffMessageKey,
            'intents' => $this->intentsForPrompt(),
        ];
    }
}
