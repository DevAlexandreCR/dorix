<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConfig extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function whatsappLine(): BelongsTo
    {
        return $this->belongsTo(WhatsAppLine::class);
    }
}
