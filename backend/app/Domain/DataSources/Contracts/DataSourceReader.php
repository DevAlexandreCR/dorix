<?php

namespace App\Domain\DataSources\Contracts;

use App\Models\DataSource;

interface DataSourceReader
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(DataSource $dataSource, array $filters): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(DataSource $dataSource, string $id): ?array;
}
