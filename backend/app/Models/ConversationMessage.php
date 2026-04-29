<?php

namespace App\Models;

use App\Enums\MessageDirection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationMessage extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }
}
