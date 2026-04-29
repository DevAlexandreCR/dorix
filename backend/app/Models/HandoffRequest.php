<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffRequest extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'accepted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
