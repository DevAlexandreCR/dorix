<?php

namespace App\Http\Controllers\Api;

use App\Domain\Connectors\GoogleCalendar\GoogleCalendarOAuthService;
use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Models\WhatsAppLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminCalendarConnectionController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected GoogleCalendarOAuthService $oauth,
    ) {
    }

    public function store(Request $request, WhatsAppLine $whatsappLine): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $line = WhatsAppLine::query()
            ->forTenant($tenant->getKey())
            ->findOrFail($whatsappLine->getKey());

        return response()->json([
            'data' => [
                'consent_url' => $this->oauth->buildConsentUrl($tenant, $line),
            ],
        ]);
    }
}
