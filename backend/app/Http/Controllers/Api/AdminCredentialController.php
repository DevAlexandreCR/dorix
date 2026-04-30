<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UpsertCredentialRequest;
use App\Models\ApiCredential;
use App\Models\WhatsAppLine;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminCredentialController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function upsert(UpsertCredentialRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManagePlatform->value);

        $payload = $request->validated();
        $scopeType = $payload['scope_type'];
        $line = null;
        $scopeKey = TenantScopeKey::forTenant($tenant);

        if ($scopeType === 'whatsapp_line') {
            $lineId = $payload['whatsapp_line_id'] ?? null;

            if (! is_numeric($lineId)) {
                throw ValidationException::withMessages([
                    'whatsapp_line_id' => ['A tenant-owned WhatsApp line is required for whatsapp_line scoped credentials.'],
                ]);
            }

            $line = WhatsAppLine::query()
                ->forTenant($tenant->getKey())
                ->findOrFail((int) $lineId);

            $scopeKey = TenantScopeKey::forWhatsAppLine($line);
        }

        $credential = ApiCredential::query()->firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'scope_key' => $scopeKey,
            'provider' => $payload['provider'],
            'credential_key' => $payload['credential_key'],
        ]);

        $credential->forceFill([
            'tenant_id' => $tenant->getKey(),
            'whatsapp_line_id' => $line?->getKey(),
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'provider' => $payload['provider'],
            'credential_key' => $payload['credential_key'],
            'secret' => $payload['secret'],
            'metadata' => $payload['metadata'] ?? ($credential->metadata ?? []),
        ])->save();

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'credential_upserted',
            actorUserId: $request->user()?->getKey(),
            target: $credential,
            payload: [
                'scope_key' => $scopeKey,
                'provider' => $credential->provider,
                'credential_key' => $credential->credential_key,
                'whatsapp_line_id' => $line?->getKey(),
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeCredentialMetadata($credential->fresh()->load('whatsappLine:id,name,display_phone_number')),
        ]);
    }
}
