<?php

namespace App\Models;

use App\Enums\WhatsAppConnectionMode;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppLine extends TenantScopedModel
{
    protected $table = 'whatsapp_lines';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'metadata' => 'array',
            'connection_mode' => WhatsAppConnectionMode::class,
        ];
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class, 'whatsapp_line_id');
    }

    public function agentConfigs(): HasMany
    {
        return $this->hasMany(AgentConfig::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function tenantToolConfigs(): HasMany
    {
        return $this->hasMany(TenantToolConfig::class);
    }
}
