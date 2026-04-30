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
use Smalot\PdfParser\Parser as PdfParser;

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
        protected PdfParser $pdfParser,
    ) {}

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
            throw new DataSourceImportException('The uploaded knowledge source file is no longer available in storage.');
        }

        $sourceType = $this->sourceType($dataSource, $uploadedFile->metadata['extension'] ?? null);

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
                    'source_type' => $sourceType,
                ],
            ]),
        ])->save();

        $dataSource->forceFill([
            'status' => 'importing',
        ])->save();

        try {
            $indexed = $this->indexFile($dataSource, $import, $filePath, $sourceType, (string) $uploadedFile->original_name);
            $chunks = $indexed['chunks'];

            if ($chunks === []) {
                throw new DataSourceImportException('The uploaded knowledge source did not produce any retrievable content chunks.');
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

            $sourceSummaries = $this->sheetSummaries($chunks);
            $resultMetadata = [
                'source_type' => $sourceType,
                'source_names' => $indexed['source_names'],
                'datasets' => $datasets,
                'chunk_types' => array_values(array_unique(array_map(
                    static fn (array $chunk): string => $chunk['chunk_type'],
                    $chunks,
                ))),
                'sheets' => $sourceSummaries,
            ];

            if ($indexed['sheet_names'] !== []) {
                $resultMetadata['sheet_names'] = $indexed['sheet_names'];
            }

            $result = new DataSourceImportResult(
                processedSheetCount: $indexed['processed_section_count'],
                generatedChunkCount: count($chunks),
                datasets: $datasets,
                metadata: $resultMetadata,
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
                    'source_type' => $sourceType,
                    'retrieval_mode' => 'document_chunks',
                    'last_import_id' => $import->getKey(),
                    'last_imported_at' => now()->toIso8601String(),
                    'sheet_names' => $indexed['sheet_names'],
                    'source_names' => $indexed['source_names'],
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
     * @return array{
     *     chunks: array<int, array<string, mixed>>,
     *     processed_section_count: int,
     *     sheet_names: array<int, string>,
     *     source_names: array<int, string>
     * }
     */
    protected function indexFile(
        DataSource $dataSource,
        DataSourceImport $import,
        string $filePath,
        string $sourceType,
        string $originalName,
    ): array {
        return match ($sourceType) {
            'excel', 'xlsx' => $this->indexWorkbook($dataSource, $import, $filePath),
            'csv' => $this->indexCsv($dataSource, $import, $filePath, $originalName),
            'txt' => $this->indexText($dataSource, $import, $filePath, $originalName, 'txt'),
            'pdf' => $this->indexPdf($dataSource, $import, $filePath, $originalName),
            default => throw new DataSourceImportException(sprintf('Unsupported knowledge source type "%s".', $sourceType)),
        };
    }

    /**
     * @return array{
     *     chunks: array<int, array<string, mixed>>,
     *     processed_section_count: int,
     *     sheet_names: array<int, string>,
     *     source_names: array<int, string>
     * }
     */
    protected function indexWorkbook(DataSource $dataSource, DataSourceImport $import, string $filePath): array
    {
        $workbook = $this->parser->parse($filePath);

        return [
            'chunks' => $this->buildChunks($dataSource, $import, $workbook),
            'processed_section_count' => count($workbook),
            'sheet_names' => array_keys($workbook),
            'source_names' => array_keys($workbook),
        ];
    }

    /**
     * @return array{
     *     chunks: array<int, array<string, mixed>>,
     *     processed_section_count: int,
     *     sheet_names: array<int, string>,
     *     source_names: array<int, string>
     * }
     */
    protected function indexCsv(DataSource $dataSource, DataSourceImport $import, string $filePath, string $originalName): array
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new DataSourceImportException('The uploaded CSV file could not be opened.');
        }

        try {
            $rows = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = array_map(static fn (mixed $value): string => trim((string) $value), $row);
            }
        } finally {
            fclose($handle);
        }

        $sourceName = pathinfo($originalName, PATHINFO_FILENAME) ?: 'CSV';

        return [
            'chunks' => $this->buildTableChunks($dataSource, $import, $sourceName, $rows),
            'processed_section_count' => 1,
            'sheet_names' => [],
            'source_names' => [$sourceName],
        ];
    }

    /**
     * @return array{
     *     chunks: array<int, array<string, mixed>>,
     *     processed_section_count: int,
     *     sheet_names: array<int, string>,
     *     source_names: array<int, string>
     * }
     */
    protected function indexText(
        DataSource $dataSource,
        DataSourceImport $import,
        string $filePath,
        string $originalName,
        string $sourceType,
    ): array {
        $content = file_get_contents($filePath);

        if (! is_string($content)) {
            throw new DataSourceImportException('The uploaded text file could not be read.');
        }

        $sourceName = pathinfo($originalName, PATHINFO_FILENAME) ?: Str::upper($sourceType);

        return [
            'chunks' => $this->buildTextFileChunks($dataSource, $import, $sourceName, $content, $sourceType),
            'processed_section_count' => 1,
            'sheet_names' => [],
            'source_names' => [$sourceName],
        ];
    }

    /**
     * @return array{
     *     chunks: array<int, array<string, mixed>>,
     *     processed_section_count: int,
     *     sheet_names: array<int, string>,
     *     source_names: array<int, string>
     * }
     */
    protected function indexPdf(DataSource $dataSource, DataSourceImport $import, string $filePath, string $originalName): array
    {
        $content = trim($this->pdfParser->parseFile($filePath)->getText());

        if ($content === '') {
            throw new DataSourceImportException('The uploaded PDF did not contain extractable text.');
        }

        $sourceName = pathinfo($originalName, PATHINFO_FILENAME) ?: 'PDF';

        return [
            'chunks' => $this->buildTextFileChunks($dataSource, $import, $sourceName, $content, 'pdf'),
            'processed_section_count' => 1,
            'sheet_names' => [],
            'source_names' => [$sourceName],
        ];
    }

    protected function sourceType(DataSource $dataSource, mixed $extension): string
    {
        $type = strtolower(trim((string) $dataSource->type));

        if ($type === 'excel') {
            return 'excel';
        }

        if (in_array($type, ['xlsx', 'csv', 'txt', 'pdf'], true)) {
            return $type;
        }

        $extension = strtolower(trim((string) $extension));

        if (in_array($extension, ['xlsx', 'csv', 'txt', 'pdf'], true)) {
            return $extension;
        }

        return $type;
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

        if (! is_array($header) || count(array_filter($header, static fn (string $value): bool => trim($value) !== '')) < 2) {
            return [];
        }

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
     * @return array<int, array<string, mixed>>
     */
    protected function buildTextFileChunks(
        DataSource $dataSource,
        DataSourceImport $import,
        string $sourceName,
        string $content,
        string $sourceType,
    ): array {
        $content = $this->normalizeTextContent($content);

        if ($content === '') {
            return [];
        }

        $chunks = [];
        $maxLength = 2400;
        $paragraphs = preg_split("/\n{2,}/", $content) ?: [];
        $buffer = '';
        $chunkIndex = 1;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($buffer !== '' && mb_strlen($buffer."\n\n".$paragraph) > $maxLength) {
                $chunks[] = $this->textFileChunk($dataSource, $import, $sourceName, $buffer, $sourceType, $chunkIndex++);
                $buffer = '';
            }

            if (mb_strlen($paragraph) > $maxLength) {
                $remaining = $paragraph;

                while ($remaining !== '') {
                    $part = trim(mb_substr($remaining, 0, $maxLength));
                    $remaining = mb_substr($remaining, $maxLength);

                    $part = trim($part);

                    if ($part !== '') {
                        $chunks[] = $this->textFileChunk($dataSource, $import, $sourceName, $part, $sourceType, $chunkIndex++);
                    }
                }

                continue;
            }

            $buffer = $buffer === '' ? $paragraph : $buffer."\n\n".$paragraph;
        }

        if (trim($buffer) !== '') {
            $chunks[] = $this->textFileChunk($dataSource, $import, $sourceName, $buffer, $sourceType, $chunkIndex);
        }

        return $chunks;
    }

    /**
     * @return array<string, mixed>
     */
    protected function textFileChunk(
        DataSource $dataSource,
        DataSourceImport $import,
        string $sourceName,
        string $content,
        string $sourceType,
        int $chunkIndex,
    ): array {
        return [
            'tenant_id' => $dataSource->tenant_id,
            'data_source_id' => $dataSource->getKey(),
            'data_source_import_id' => $import->getKey(),
            'chunk_type' => 'text_block',
            'sheet_name' => $sourceName,
            'row_start' => null,
            'row_end' => null,
            'section_key' => sprintf('%s-%s-%d', Str::slug($sourceName), $sourceType, $chunkIndex),
            'content_text' => $content,
            'structured_payload' => [
                'source_name' => $sourceName,
                'chunk_index' => $chunkIndex,
            ],
            'metadata' => [
                'datasets' => ['knowledge'],
                'source_type' => $sourceType,
                'sheet_kind' => 'knowledge_text',
                'chunk_index' => $chunkIndex,
            ],
        ];
    }

    protected function normalizeTextContent(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/[ \t]+/", ' ', $content) ?? $content;
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return trim($content);
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
