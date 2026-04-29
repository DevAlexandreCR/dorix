<?php

namespace App\Domain\DataSources\DTO;

final readonly class DataSourceImportResult
{
    /**
     * @param  array<int, string>  $datasets
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $processedSheetCount,
        public int $generatedChunkCount,
        public array $datasets = [],
        public array $metadata = [],
    ) {
    }

    public function totalUnits(): int
    {
        return $this->generatedChunkCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'processed_sheet_count' => $this->processedSheetCount,
            'generated_chunk_count' => $this->generatedChunkCount,
            'total_units' => $this->totalUnits(),
            'datasets' => $this->datasets,
            'metadata' => $this->metadata,
        ];
    }
}
