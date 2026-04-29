<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceChunk extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'structured_payload' => 'array',
            'metadata' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function dataSourceImport(): BelongsTo
    {
        return $this->belongsTo(DataSourceImport::class);
    }
}
