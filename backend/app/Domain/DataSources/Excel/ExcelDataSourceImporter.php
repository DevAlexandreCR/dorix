<?php

namespace App\Domain\DataSources\Excel;

use App\Domain\DataSources\Contracts\DataSourceImporter;
use App\Domain\DataSources\DTO\DataSourceImportResult;
use App\Domain\DataSources\Exceptions\DataSourceImportException;
use App\Models\DataSource;
use App\Models\DataSourceChunk;
use App\Models\DataSourceImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExcelDataSourceImporter implements DataSourceImporter
{
    /**
     * @var array<string, array<int, string>>
     */
    protected array $inventorySheetAliases = [
        'inventory',
        'inventario',
        'catalog',
        'catalogo',
        'products',
        'productos',
        'servicios',
    ];

    public function __construct(
        protected NativeXlsxParser $parser,
    ) {
    }

    public function sync(DataSourceImport $import): DataSourceImportResult
    {
        $import->loadMissing(['dataSource', 'uploadedFile']);
        $dataSource = $import->dataSource;
        $uploadedFile = $import->uploadedFile;

        if (! $dataSource || ! $uploadedFile) {
            throw new DataSourceImportException('The import job is missing its data source or uploaded file linkage.');
        }

        $filePath = Storage::disk($uploadedFile->disk)->path($uploadedFile->path);

        if (! is_file($filePath)) {
            throw new DataSourceImportException('The uploaded Excel file is no longer available in storage.');
        }

        $import->forceFill([
            'status' => 'processing',
            'attempts_count' => $import->attempts_count + 1,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => array_merge($import->metadata ?? [], [
                'source_file' => [
                    'uploaded_file_id' => $uploadedFile->getKey(),
                    'original_name' => $uploadedFile->original_name,
                    'size_bytes' => $uploadedFile->size_bytes,
                    'checksum_prefix' => $uploadedFile->checksum ? substr($uploadedFile->checksum, 0, 12) : null,
                ],
            ]),
        ])->save();

        $dataSource->forceFill([
            'status' => 'importing',
        ])->save();

        try {
            $workbook = $this->parser->parse($filePath);
            $chunks = $this->buildChunks($dataSource, $import, $workbook);

            if ($chunks === []) {
                throw new DataSourceImportException('The workbook did not produce any retrievable content chunks.');
            }

            DB::transaction(function () use ($dataSource, $chunks): void {
                DataSourceChunk::query()
                    ->forTenant($dataSource->tenant_id)
                    ->where('data_source_id', $dataSource->getKey())
                    ->delete();

                foreach ($chunks as $chunk) {
                    DataSourceChunk::query()->create($chunk);
                }
            });

            $datasets = collect($chunks)
                ->flatMap(fn (array $chunk): array => $chunk['metadata']['datasets'] ?? [])
                ->unique()
                ->values()
                ->all();

            $sheetSummaries = $this->sheetSummaries($chunks);

            $result = new DataSourceImportResult(
                processedSheetCount: count($workbook),
                generatedChunkCount: count($chunks),
                datasets: $datasets,
                metadata: [
                    'sheet_names' => array_keys($workbook),
                    'datasets' => $datasets,
                    'chunk_types' => array_values(array_unique(array_map(
                        static fn (array $chunk): string => $chunk['chunk_type'],
                        $chunks,
                    ))),
                    'sheets' => $sheetSummaries,
                ],
            );

            $import->forceFill([
                'status' => 'succeeded',
                'processed_sheet_count' => $result->processedSheetCount,
                'generated_chunk_count' => $result->generatedChunkCount,
                'metadata' => array_merge($import->metadata ?? [], $result->metadata, [
                    'completed_at' => now()->toIso8601String(),
                ]),
                'finished_at' => now(),
            ])->save();

            $dataSource->forceFill([
                'status' => 'ready',
                'last_synced_at' => now(),
                'metadata' => array_merge($dataSource->metadata ?? [], [
                    'datasets' => $datasets,
                    'retrieval_mode' => 'document_chunks',
                    'last_import_id' => $import->getKey(),
                    'last_imported_at' => now()->toIso8601String(),
                    'sheet_names' => array_keys($workbook),
                    'last_import_summary' => [
                        'processed_sheet_count' => $result->processedSheetCount,
                        'generated_chunk_count' => $result->generatedChunkCount,
                        'datasets' => $datasets,
                    ],
                ]),
            ])->save();

            return $result;
        } catch (\Throwable $exception) {
            $import->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'metadata' => array_merge($import->metadata ?? [], [
                    'failed_at' => now()->toIso8601String(),
                    'failure' => [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ],
                ]),
                'finished_at' => now(),
            ])->save();

            $dataSource->forceFill([
                'status' => 'failed',
                'metadata' => array_merge($dataSource->metadata ?? [], [
                    'last_import_error' => $exception->getMessage(),
                    'last_import_id' => $import->getKey(),
                    'last_import_error_class' => $exception::class,
                ]),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $workbook
     * @return array<int, array<string, mixed>>
     */
    protected function buildChunks(DataSource $dataSource, DataSourceImport $import, array $workbook): array
    {
        $chunks = [];

        foreach ($workbook as $sheetName => $rows) {
            if ($rows === []) {
                continue;
            }

            if ($this->isTabularSheet($rows)) {
                $chunks = [...$chunks, ...$this->buildTableChunks($dataSource, $import, $sheetName, $rows)];
                continue;
            }

            $chunk = $this->buildTextChunk($dataSource, $import, $sheetName, $rows);

            if ($chunk !== null) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function buildTableChunks(DataSource $dataSource, DataSourceImport $import, string $sheetName, array $rows): array
    {
        $header = array_shift($rows);
        $normalizedHeader = array_map(
            fn (string $value): string => $this->normalizeKey($value),
            $header,
        );

        $datasets = $this->datasetsForSheet($sheetName, $normalizedHeader);
        $chunks = [];

        foreach ($rows as $index => $row) {
            $sourceRowNumber = $index + 2;
            $rowPayload = [];

            foreach ($normalizedHeader as $columnIndex => $columnKey) {
                if ($columnKey === '') {
                    continue;
                }

                $value = trim((string) ($row[$columnIndex] ?? ''));

                if ($value === '') {
                    continue;
                }

                $rowPayload[$columnKey] = $value;
            }

            if ($rowPayload === []) {
                continue;
            }

            $contentParts = [];

            foreach ($rowPayload as $key => $value) {
                $contentParts[] = $this->labelForKey($key).': '.$value;
            }

            $chunks[] = [
                'tenant_id' => $dataSource->tenant_id,
                'data_source_id' => $dataSource->getKey(),
                'data_source_import_id' => $import->getKey(),
                'chunk_type' => 'table_row',
                'sheet_name' => $sheetName,
                'row_start' => $sourceRowNumber,
                'row_end' => $sourceRowNumber,
                'section_key' => sprintf('%s-row-%d', Str::slug($sheetName), $sourceRowNumber),
                'content_text' => implode("\n", $contentParts),
                'structured_payload' => $rowPayload,
                'metadata' => [
                    'datasets' => $datasets,
                    'sheet_kind' => in_array('inventory', $datasets, true) ? 'inventory_table' : 'knowledge_table',
                    'header' => $normalizedHeader,
                ],
            ];
        }

        return $chunks;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, mixed>|null
     */
    protected function buildTextChunk(DataSource $dataSource, DataSourceImport $import, string $sheetName, array $rows): ?array
    {
        $lines = [];

        foreach ($rows as $row) {
            $cells = array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                $row,
            ), static fn (string $value): bool => $value !== ''));

            if ($cells === []) {
                continue;
            }

            $lines[] = implode(' | ', $cells);
        }

        $content = trim(implode("\n", $lines));

        if ($content === '') {
            return null;
        }

        return [
            'tenant_id' => $dataSource->tenant_id,
            'data_source_id' => $dataSource->getKey(),
            'data_source_import_id' => $import->getKey(),
            'chunk_type' => 'text_block',
            'sheet_name' => $sheetName,
            'row_start' => 1,
            'row_end' => count($rows),
            'section_key' => sprintf('%s-text', Str::slug($sheetName)),
            'content_text' => $content,
            'structured_payload' => [
                'rows' => $rows,
            ],
            'metadata' => [
                'datasets' => ['knowledge'],
                'sheet_kind' => 'knowledge_text',
            ],
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function isTabularSheet(array $rows): bool
    {
        if (count($rows) < 2) {
            return false;
        }

        $header = $rows[0];

        return count(array_filter($header, static fn (string $value): bool => trim($value) !== '')) >= 2;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<int, string>
     */
    protected function datasetsForSheet(string $sheetName, array $header): array
    {
        $datasets = ['knowledge'];
        $normalizedSheet = $this->normalizeKey($sheetName);

        if (in_array($normalizedSheet, $this->inventorySheetAliases, true)) {
            $datasets[] = 'inventory';
        }

        if (array_intersect($header, ['sku', 'precio', 'price', 'categoria', 'category', 'cantidad', 'stock', 'availability', 'disponibilidad']) !== []) {
            $datasets[] = 'inventory';
        }

        return array_values(array_unique($datasets));
    }

    protected function normalizeKey(string $value): string
    {
        $normalized = Str::ascii(Str::lower(trim($value)));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';

        return trim($normalized, '_');
    }

    protected function labelForKey(string $key): string
    {
        return Str::headline(str_replace('_', ' ', $key));
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<int, array<string, mixed>>
     */
    protected function sheetSummaries(array $chunks): array
    {
        return collect($chunks)
            ->groupBy('sheet_name')
            ->map(function ($sheetChunks, $sheetName): array {
                $rows = $sheetChunks
                    ->flatMap(static fn (array $chunk): array => array_filter([
                        $chunk['row_start'] ?? null,
                        $chunk['row_end'] ?? null,
                    ], static fn (mixed $value): bool => is_numeric($value)))
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->values();

                return [
                    'sheet_name' => $sheetName,
                    'chunk_count' => $sheetChunks->count(),
                    'chunk_types' => $sheetChunks->pluck('chunk_type')->unique()->values()->all(),
                    'datasets' => $sheetChunks
                        ->flatMap(static fn (array $chunk): array => $chunk['metadata']['datasets'] ?? [])
                        ->unique()
                        ->values()
                        ->all(),
                    'row_span' => [
                        'start' => $rows->min(),
                        'end' => $rows->max(),
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
