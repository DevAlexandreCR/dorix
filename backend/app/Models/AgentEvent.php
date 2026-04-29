<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentEvent extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
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

    public function whatsappLine(): BelongsTo
    {
        return $this->belongsTo(WhatsAppLine::class);
    }
}
