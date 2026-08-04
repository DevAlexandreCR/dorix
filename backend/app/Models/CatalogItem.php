<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'duration_minutes' => 'integer',
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function assessmentItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'assessment_item_id');
    }
}
