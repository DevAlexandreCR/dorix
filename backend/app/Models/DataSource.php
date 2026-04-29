<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }
}
