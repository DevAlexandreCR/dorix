<?php

namespace App\Domain\DataSources\Contracts;

use App\Domain\DataSources\DTO\DataSourceImportResult;
use App\Models\DataSourceImport;

interface DataSourceImporter
{
    public function sync(DataSourceImport $import): DataSourceImportResult;
}
