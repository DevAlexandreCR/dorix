<?php

namespace App\Jobs;

use App\Domain\DataSources\Contracts\DataSourceImporter;
use App\Models\DataSourceImport;
use App\Support\Tenancy\Jobs\RunsInTenantContext;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportExcelDataSourceJob implements ShouldQueue
{
    use Queueable;
    use RunsInTenantContext;

    public int $tries = 1;

    public function __construct(
        public int $tenantId,
        public int $dataSourceImportId,
    ) {
    }

    public function handle(TenantContextManager $manager, DataSourceImporter $importer): void
    {
        $this->runInTenantContext($manager, $this->tenantId, function () use ($importer): void {
            $import = DataSourceImport::query()
                ->forTenant($this->tenantId)
                ->findOrFail($this->dataSourceImportId);

            $importer->sync($import);
        });
    }
}
