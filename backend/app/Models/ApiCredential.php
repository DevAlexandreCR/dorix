<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'metadata' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function whatsappLine(): BelongsTo
    {
        return $this->belongsTo(WhatsAppLine::class);
    }
}
