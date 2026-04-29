<?php

namespace App\Domain\WhatsApp\DTO;

use App\Models\Tenant;
use App\Models\WhatsAppLine;

final readonly class ResolvedWhatsAppLine
{
    public function __construct(
        public WhatsAppLine $line,
        public Tenant $tenant,
    ) {
    }

    public function tenantId(): int
    {
        return $this->tenant->getKey();
    }
}
