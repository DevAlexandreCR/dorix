<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function whatsappLine(): BelongsTo
    {
        return $this->belongsTo(WhatsAppLine::class);
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(ConversationState::class);
    }

    public function handoffRequests(): HasMany
    {
        return $this->hasMany(HandoffRequest::class);
    }
}
