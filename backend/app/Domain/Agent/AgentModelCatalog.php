<?php

namespace App\Domain\Agent;

use App\Models\AgentConfig;

final class AgentModelCatalog
{
    public const DEFAULT_MODEL_KEY = 'balanced';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $models;

    public function __construct()
    {
        $this->models = [
            'balanced' => [
                'key' => 'balanced',
                'model_id' => 'gpt-5.4-mini',
                'label' => 'Equilibrado',
                'description' => 'Recomendado para la mayoría de clientes. Buen balance entre calidad, velocidad y costo.',
                'recommended' => true,
                'visible_in_ui' => true,
                'sort_order' => 10,
            ],
            'high_accuracy' => [
                'key' => 'high_accuracy',
                'model_id' => 'gpt-5.5',
                'label' => 'Alta precisión',
                'description' => 'Para conversaciones más complejas donde importa priorizar calidad de respuesta.',
                'recommended' => false,
                'visible_in_ui' => true,
                'sort_order' => 20,
            ],
            'savings' => [
                'key' => 'savings',
                'model_id' => 'gpt-5.4-nano',
                'label' => 'Ahorro',
                'description' => 'Para operación sensible a costo y latencia en flujos más simples.',
                'recommended' => false,
                'visible_in_ui' => true,
                'sort_order' => 30,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->models);
    }

    public function has(?string $key): bool
    {
        return array_key_exists($this->normalize($key), $this->models);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(?string $key): ?array
    {
        $normalized = $this->normalize($key);

        return $normalized !== '' ? ($this->models[$normalized] ?? null) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(?string $key): array
    {
        return $this->find($key) ?? $this->recommended();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function available(bool $visibleOnly = true): array
    {
        $models = array_values(array_filter(
            $this->models,
            static fn (array $model): bool => ! $visibleOnly || (bool) ($model['visible_in_ui'] ?? false),
        ));

        usort($models, static function (array $left, array $right): int {
            return ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0));
        });

        return $models;
    }

    public function defaultKey(): string
    {
        $configured = $this->normalize((string) config('services.openai.default_model_key', self::DEFAULT_MODEL_KEY));

        if ($this->has($configured)) {
            return $configured;
        }

        return (string) $this->recommended()['key'];
    }

    /**
     * @return array<string, mixed>
     */
    public function recommended(): array
    {
        foreach ($this->available(false) as $model) {
            if ((bool) ($model['recommended'] ?? false)) {
                return $model;
            }
        }

        return $this->models[self::DEFAULT_MODEL_KEY];
    }

    public function keyFromConfig(AgentConfig|array|null $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $modelKey = $source instanceof AgentConfig
            ? $source->getAttribute('model_key')
            : ($source['model_key'] ?? null);

        if ($this->has(is_string($modelKey) ? $modelKey : null)) {
            return $this->normalize((string) $modelKey);
        }

        $legacyModel = $source instanceof AgentConfig
            ? $source->model
            : ($source['model'] ?? null);

        return $this->legacyModelToKey(is_string($legacyModel) ? $legacyModel : null);
    }

    /**
     * @return array{key:string,model_id:string,label:string,description:string,recommended:bool,visible_in_ui:bool,sort_order:int,source:string}
     */
    public function effectiveForConfigs(?AgentConfig $lineConfig, ?AgentConfig $tenantConfig): array
    {
        $lineKey = $this->keyFromConfig($lineConfig);

        if ($lineKey !== null) {
            return [
                ...$this->resolve($lineKey),
                'source' => 'line',
            ];
        }

        $tenantKey = $this->keyFromConfig($tenantConfig);

        if ($tenantKey !== null) {
            return [
                ...$this->resolve($tenantKey),
                'source' => 'tenant',
            ];
        }

        return [
            ...$this->resolve($this->defaultKey()),
            'source' => 'system_default',
        ];
    }

    public function legacyModelToKey(?string $legacyModel): ?string
    {
        return match ($this->normalize($legacyModel)) {
            'gpt-5-mini', 'gpt-5.4-mini' => 'balanced',
            'gpt-5.1', 'gpt-5.5' => 'high_accuracy',
            'gpt-5.4-nano' => 'savings',
            default => null,
        };
    }

    protected function normalize(?string $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
