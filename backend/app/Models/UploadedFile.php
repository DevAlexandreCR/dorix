<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadedFile extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
