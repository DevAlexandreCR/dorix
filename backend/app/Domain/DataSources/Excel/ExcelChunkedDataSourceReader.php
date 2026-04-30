<?php

namespace App\Domain\DataSources\Excel;

use App\Domain\DataSources\Contracts\DataSourceReader;
use App\Models\DataSource;
use App\Models\DataSourceChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ExcelChunkedDataSourceReader implements DataSourceReader
{
    public function search(DataSource $dataSource, array $filters): array
    {
        $mode = $this->stringValue($filters['mode'] ?? null) ?: 'knowledge';

        return match ($mode) {
            'inventory' => $this->searchInventory($dataSource, $filters),
            default => $this->searchKnowledge($dataSource, $filters),
        };
    }

    public function find(DataSource $dataSource, string $id): ?array
    {
        $chunk = DataSourceChunk::query()
            ->forTenant($dataSource->tenant_id)
            ->where('data_source_id', $dataSource->getKey())
            ->where(function (Builder $query) use ($id): void {
                $query->whereKey($id)
                    ->orWhere('section_key', $id);
            })
            ->first();

        return $chunk ? $this->mapChunk($chunk, 100) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function searchInventory(DataSource $dataSource, array $filters): array
    {
        $query = $this->stringValue($filters['query'] ?? null);
        $limit = $this->limit($filters['limit'] ?? null, 3, 5);
        $filtersPayload = is_array($filters['filters'] ?? null) ? $filters['filters'] : [];
        $tokens = $this->tokens(trim($query.' '.$this->flattenFilters($filtersPayload)));

        $records = DataSourceChunk::query()
            ->forTenant($dataSource->tenant_id)
            ->where('data_source_id', $dataSource->getKey())
            ->whereIn('chunk_type', ['table_row', 'table_block'])
            ->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $builder->orWhereRaw('LOWER(content_text) LIKE ?', ['%'.$token.'%']);
                }
            })
            ->limit(60)
            ->get();

        if ($records->isEmpty() && $tokens !== []) {
            $records = DataSourceChunk::query()
                ->forTenant($dataSource->tenant_id)
                ->where('data_source_id', $dataSource->getKey())
                ->whereIn('chunk_type', ['table_row', 'table_block'])
                ->limit(60)
                ->get();
        }

        return $records
            ->map(fn (DataSourceChunk $chunk): array => $this->mapChunk(
                $chunk,
                $this->scoreChunk($chunk->content_text, $tokens, $query),
            ))
            ->filter(fn (array $match): bool => $match['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function searchKnowledge(DataSource $dataSource, array $filters): array
    {
        $query = $this->stringValue($filters['question'] ?? null) ?: $this->stringValue($filters['query'] ?? null);
        $topic = $this->stringValue($filters['topic'] ?? null);
        $limit = $this->limit($filters['limit'] ?? null, 3, 5);
        $tokens = $this->tokens(trim($query.' '.$topic));

        $records = DataSourceChunk::query()
            ->forTenant($dataSource->tenant_id)
            ->where('data_source_id', $dataSource->getKey())
            ->where(function (Builder $builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $builder->orWhereRaw('LOWER(content_text) LIKE ?', ['%'.$token.'%']);
                }
            })
            ->limit(60)
            ->get();

        if ($records->isEmpty() && $tokens !== []) {
            $records = DataSourceChunk::query()
                ->forTenant($dataSource->tenant_id)
                ->where('data_source_id', $dataSource->getKey())
                ->limit(60)
                ->get();
        }

        return $records
            ->map(fn (DataSourceChunk $chunk): array => $this->mapChunk(
                $chunk,
                $this->scoreChunk($chunk->content_text, $tokens, $query),
            ))
            ->filter(fn (array $match): bool => $match['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapChunk(DataSourceChunk $chunk, int $score): array
    {
        return [
            'id' => $chunk->getKey(),
            'content_text' => $chunk->content_text,
            'score' => $score,
            'source_ref' => [
                'sheet_name' => $chunk->sheet_name,
                'row_start' => $chunk->row_start,
                'row_end' => $chunk->row_end,
                'section_key' => $chunk->section_key,
                'chunk_type' => $chunk->chunk_type,
            ],
            'structured_payload' => $chunk->structured_payload ?? [],
            'metadata' => $chunk->metadata ?? [],
        ];
    }

    protected function scoreChunk(string $contentText, array $tokens, string $fullQuery): int
    {
        $content = $this->normalizeSearchText($contentText);
        $normalizedQuery = $this->normalizeSearchText(trim($fullQuery));
        $score = 0;

        if ($normalizedQuery !== '' && str_contains($content, $normalizedQuery)) {
            $score += 20;
        }

        foreach ($tokens as $token) {
            if (str_contains($content, $token)) {
                $score += 6;
            }
        }

        return $score;
    }

    protected function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min($max, (int) $value));
    }

    protected function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function flattenFilters(array $filters): string
    {
        $parts = [];

        foreach ($filters as $value) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, string>
     */
    protected function tokens(string $value): array
    {
        $normalized = $this->normalizeSearchText($value);
        $parts = preg_split('/[^[:alnum:]]+/u', $normalized) ?: [];

        return array_values(array_filter($parts, static fn (string $token): bool => mb_strlen($token) >= 2));
    }

    protected function normalizeSearchText(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
