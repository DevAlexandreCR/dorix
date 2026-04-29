<?php

namespace App\Models;

use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUser extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
