<?php

namespace App\Jobs;

use App\Domain\DataSources\Contracts\DataSourceImporter;
use App\Domain\DataSources\DTO\DataSourceImportResult;
use App\Models\DataSourceImport;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\Jobs\RunsInTenantContext;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportDataSourceJob implements ShouldQueue
{
    use Queueable;
    use RunsInTenantContext;

    public const QUEUE = 'imports';

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 45];

    public function __construct(
        public int $tenantId,
        public int $dataSourceImportId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        TenantContextManager $manager,
        DataSourceImporter $importer,
        AgentEventRecorder $events,
    ): void {
        $this->runInTenantContext($manager, $this->tenantId, function () use ($importer, $events): void {
            $import = DataSourceImport::query()
                ->forTenant($this->tenantId)
                ->with(['dataSource', 'uploadedFile'])
                ->findOrFail($this->dataSourceImportId);

            $events->record($this->tenantId, 'data_source_import_started', [
                'payload' => [
                    'data_source_id' => $import->data_source_id,
                    'import_id' => $import->getKey(),
                    'attempt' => $this->job?->attempts() ?? 1,
                    'queue' => self::QUEUE,
                    'uploaded_file_id' => $import->uploaded_file_id,
                    'source_type' => $import->dataSource?->type,
                ],
            ]);

            $result = $importer->sync($import);

            $events->record($this->tenantId, 'data_source_import_succeeded', [
                'payload' => $this->successPayload($import, $result),
            ]);
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'tenant:'.$this->tenantId,
            'import:'.$this->dataSourceImportId,
            'queue:'.self::QUEUE,
        ];
    }

    public function failed(Throwable $exception): void
    {
        $import = DataSourceImport::query()->with('dataSource')->find($this->dataSourceImportId);

        app(AgentEventRecorder::class)->record($this->tenantId, 'data_source_import_failed', [
            'payload' => [
                'data_source_id' => $import?->data_source_id,
                'import_id' => $this->dataSourceImportId,
                'attempt' => $this->job?->attempts() ?? $this->tries,
                'queue' => self::QUEUE,
                'source_type' => $import?->dataSource?->type,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function successPayload(DataSourceImport $import, DataSourceImportResult $result): array
    {
        return [
            'data_source_id' => $import->data_source_id,
            'import_id' => $import->getKey(),
            'attempt' => $this->job?->attempts() ?? 1,
            'queue' => self::QUEUE,
            'source_type' => $import->dataSource?->type,
            'processed_sheet_count' => $result->processedSheetCount,
            'generated_chunk_count' => $result->generatedChunkCount,
            'datasets' => $result->datasets,
            'metadata' => $result->metadata,
        ];
    }
}
