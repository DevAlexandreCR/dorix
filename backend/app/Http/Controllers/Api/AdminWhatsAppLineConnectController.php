<?php

namespace App\Http\Controllers\Api;

use App\Domain\WhatsApp\EmbeddedSignupConnector;
use App\Domain\WhatsApp\Exceptions\EmbeddedSignupException;
use App\Enums\Permission;
use App\Enums\WhatsAppConnectionMode;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\ConnectWhatsAppLineRequest;
use App\Models\WhatsAppLine;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\Audit\AuditEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orchestrates the Meta Embedded Signup connect flow (design.md D2):
 * a cross-tenant conflict pre-check runs before any Graph or DB call,
 * then the authorization code is exchanged, the WABA/number are
 * provisioned in Graph, an initial name is resolved, and the line and
 * its credentials are persisted in one transaction. Kept as a
 * dedicated controller because its request/response contract differs
 * materially from the manual `store`/`update`/`destroy` endpoints.
 */
class AdminWhatsAppLineConnectController
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected EmbeddedSignupConnector $connector,
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
    ) {
    }

    public function store(ConnectWhatsAppLineRequest $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageTenant->value, $tenant);

        $phoneNumberId = (string) $request->validated('phone_number_id');
        $wabaId = (string) $request->validated('waba_id');
        $connectionMode = WhatsAppConnectionMode::from($request->validated('connection_mode'));
        $name = $request->validated('name');

        // Cross-tenant conflict pre-check MUST run before any Graph call
        // or DB write (design.md D2 idempotency/conflict section): a
        // number already connected to another tenant is rejected
        // without revealing which tenant owns it, and without ever
        // touching Graph.
        $existingLine = WhatsAppLine::query()->where('phone_number_id', $phoneNumberId)->first();

        if ($existingLine !== null && $existingLine->tenant_id !== $tenant->getKey()) {
            return response()->json([
                'message' => __('api.whatsapp.embedded_signup.number_already_connected'),
            ], Response::HTTP_CONFLICT);
        }

        try {
            $accessToken = $this->connector->exchangeCode((string) $request->validated('code'));
            $registrationPin = $this->connector->provisionPhoneNumber($wabaId, $phoneNumberId, $accessToken, $connectionMode);
            $lineName = is_string($name) && $name !== ''
                ? $name
                : $this->connector->fetchInitialLineName($phoneNumberId, $accessToken);
        } catch (EmbeddedSignupException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $line = $this->connector->persistConnection(
            $tenant,
            $phoneNumberId,
            $wabaId,
            $connectionMode,
            $lineName,
            $accessToken,
            $registrationPin,
        );

        $this->audit->record(
            tenantId: $tenant->getKey(),
            eventType: 'whatsapp_line_connected',
            actorUserId: $request->user()?->getKey(),
            target: $line,
            payload: [
                'whatsapp_line_id' => $line->getKey(),
                'phone_number_id' => $line->phone_number_id,
                'connection_mode' => $connectionMode->value,
            ],
        );

        return response()->json([
            'data' => $this->builder->serializeWhatsAppLine($line->fresh()),
        ], Response::HTTP_CREATED);
    }
}
