<?php

namespace App\Domain\Agent;

use App\Models\AgentConfig;

final class AgentConfigSettings
{
    public const DEFAULT_AGENT_PACK_KEY = 'sales_support_v1';

    /**
     * @param  AgentConfig|array<string, mixed>|null  $source
     */
    public static function agentPackKey(AgentConfig|array|null $source): string
    {
        $settings = self::settings($source);
        $value = $settings['agent_pack_key'] ?? self::DEFAULT_AGENT_PACK_KEY;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : self::DEFAULT_AGENT_PACK_KEY;
    }

    /**
     * @param  AgentConfig|array<string, mixed>|null  $source
     */
    public static function automationEnabled(AgentConfig|array|null $source): bool
    {
        $settings = self::settings($source);

        return (bool) ($settings['automation_enabled'] ?? true);
    }

    /**
     * @param  AgentConfig|array<string, mixed>|null  $source
     */
    public static function systemPrompt(AgentConfig|array|null $source): string
    {
        $settings = self::settings($source);
        $value = $settings['system_prompt'] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  AgentConfig|array<string, mixed>|null  $source
     */
    public static function handoffCustomerMessage(AgentConfig|array|null $source, string $fallback = ''): string
    {
        $settings = self::settings($source);
        $value = $settings['handoff_customer_message'] ?? '';

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return trim($fallback);
    }

    /**
     * @param  AgentConfig|array<string, mixed>|null  $source
     * @return array<string, mixed>
     */
    protected static function settings(AgentConfig|array|null $source): array
    {
        if ($source instanceof AgentConfig) {
            return is_array($source->settings) ? $source->settings : [];
        }

        return is_array($source) ? $source : [];
    }
}
