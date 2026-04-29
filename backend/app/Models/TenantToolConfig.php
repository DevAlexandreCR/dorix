<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantToolConfig extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'overrides' => 'array',
            'bindings' => 'array',
        ];
    }

    public function whatsappLine(): BelongsTo
    {
        return $this->belongsTo(WhatsAppLine::class);
    }
}
