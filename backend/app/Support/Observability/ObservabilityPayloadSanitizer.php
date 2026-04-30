<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;

class ObservabilityPayloadSanitizer
{
    /**
     * @var array<int, string>
     */
    protected array $maskedKeys = [
        'api_key',
        'authorization',
        'password',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'webhook_verify_token',
    ];

    /**
     * @var array<int, string>
     */
    protected array $previewKeys = [
        'body',
        'error',
        'error_message',
        'handoff_reason',
        'internal_notes',
        'memory_summary',
        'query',
        'question',
        'reason',
        'reply_text',
    ];

    public function sanitizeForStorage(mixed $payload): mixed
    {
        return $this->sanitize($payload, surface: 'storage');
    }

    public function sanitizeForLogs(mixed $payload): mixed
    {
        return $this->sanitize($payload, surface: 'logs');
    }

    public function sanitizeForPresentation(mixed $payload): mixed
    {
        return $this->sanitize($payload, surface: 'presentation');
    }

    public function previewString(string $value, int $limit = 160): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $limit).'...';
    }

    protected function sanitize(
        mixed $value,
        int $depth = 0,
        string|int|null $key = null,
        string $surface = 'storage',
    ): mixed {
        if ($depth >= 6) {
            return is_array($value)
                ? ['truncated' => true, 'item_count' => count($value)]
                : '[truncated]';
        }

        $normalizedKey = is_string($key) ? Str::snake($key) : null;

        if ($normalizedKey !== null && $this->shouldMask($normalizedKey)) {
            return '[redacted]';
        }

        if ($normalizedKey === 'raw') {
            return [
                'redacted' => true,
                'reason' => 'raw_payload_not_persisted_in_observability',
                'item_count' => is_array($value) ? count($value) : null,
            ];
        }

        if ($normalizedKey === 'matches' || $normalizedKey === 'retrieved_context') {
            return $this->summarizeMatches(is_array($value) ? $value : []);
        }

        if ($this->shouldSummarizeObject($normalizedKey)) {
            return $this->summarizeStructuredPayload(is_array($value) ? $value : []);
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach (array_slice($value, 0, 25, true) as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitize(
                    $itemValue,
                    depth: $depth + 1,
                    key: is_string($itemKey) ? $itemKey : $normalizedKey,
                    surface: $surface,
                );
            }

            if (count($value) > 25) {
                $sanitized['_truncated_items'] = count($value) - 25;
            }

            return $sanitized;
        }

        if (is_string($value)) {
            $limit = $surface === 'logs' ? 120 : 180;

            if ($normalizedKey !== null && in_array($normalizedKey, $this->previewKeys, true)) {
                return $this->previewString($value, $limit);
            }

            if (mb_strlen($value) > 250) {
                return $this->previewString($value, 250);
            }
        }

        return $value;
    }

    protected function shouldMask(string $key): bool
    {
        if (in_array($key, $this->maskedKeys, true)) {
            return true;
        }

        return str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'password');
    }

    protected function shouldSummarizeObject(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        return in_array($key, [
            'collected_data',
            'customer_data',
            'lead_data',
            'structured_payload',
        ], true);
    }

    /**
     * @param  array<int, mixed>  $matches
     * @return array<int, array<string, mixed>>
     */
    protected function summarizeMatches(array $matches): array
    {
        $summaries = [];

        foreach (array_slice($matches, 0, 5) as $match) {
            if (! is_array($match)) {
                continue;
            }

            $metadata = is_array($match['metadata'] ?? null) ? $match['metadata'] : [];
            $structuredPayload = is_array($match['structured_payload'] ?? null) ? $match['structured_payload'] : [];

            $summaries[] = [
                'id' => $match['id'] ?? null,
                'score' => $match['score'] ?? null,
                'preview' => $this->previewString((string) ($match['content_text'] ?? ''), 160),
                'source_ref' => $this->sanitize($match['source_ref'] ?? [], depth: 1, key: 'source_ref'),
                'datasets' => array_values(array_filter(
                    is_array($metadata['datasets'] ?? null) ? $metadata['datasets'] : [],
                    static fn (mixed $item): bool => is_string($item) && $item !== '',
                )),
                'structured_keys' => array_values(array_slice(array_keys($structuredPayload), 0, 10)),
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function summarizeStructuredPayload(array $payload): array
    {
        return [
            'field_count' => count($payload),
            'keys' => array_values(array_slice(array_keys($payload), 0, 10)),
        ];
    }
}
