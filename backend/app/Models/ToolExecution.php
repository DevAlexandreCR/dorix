<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExecution extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'input_summary' => 'array',
            'output_summary' => 'array',
            'metadata' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class);
    }
}
