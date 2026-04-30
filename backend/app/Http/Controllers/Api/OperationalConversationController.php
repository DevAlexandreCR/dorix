<?php

namespace App\Http\Controllers\Api;

use App\Domain\Conversations\Exceptions\ConversationOperationException;
use App\Domain\Conversations\OperationalConversationService;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Enums\TenantRole;
use App\Http\Requests\Api\ConversationAssignRequest;
use App\Http\Requests\Api\ConversationHandoffRequest;
use App\Http\Requests\Api\ConversationManualReplyRequest;
use App\Http\Requests\Api\ConversationResumeRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class OperationalConversationController
{
    public function __construct(
        protected OperationalConversationService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ViewConversations, $tenant);

        $query = Conversation::query()
            ->forTenant($tenant->getKey())
            ->where('source', ConversationSource::WhatsApp->value)
            ->with([
                'assignedToUser:id,name,email',
                'whatsappLine:id,name,display_phone_number',
            ])
            ->select('conversations.*')
            ->selectSub(
                ConversationMessage::query()
                    ->select('body')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'last_message_preview',
            )
            ->selectSub(
                ConversationMessage::query()
                    ->select('direction')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'last_message_direction',
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $status = ConversationStatus::tryFrom((string) $request->string('status'));

            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if ($request->boolean('assigned_to_me')) {
            $query->where('assigned_to_user_id', $request->user()?->getKey());
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('contact_phone', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%");
            });
        }

        $limit = max(1, min((int) $request->integer('limit', 30), 100));
        $conversations = $query->limit($limit)->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $conversation): array => $this->serializeConversationSummary($conversation))->all(),
        ]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ViewConversations, $tenant);

        $conversationModel = $this->findConversation($tenant, $conversation)
            ->load([
                'assignedToUser:id,name,email',
                'whatsappLine:id,name,display_phone_number',
                'state',
                'handoffRequests' => fn ($query) => $query->latest('id'),
            ]);

        $messages = ConversationMessage::query()
            ->forTenant($tenant->getKey())
            ->where('conversation_id', $conversationModel->getKey())
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'conversation' => $this->serializeConversationDetail($conversationModel),
                'messages' => $messages->map(fn (ConversationMessage $message): array => $this->serializeMessage($message))->all(),
                'available_operators' => $this->availableOperators($tenant)->all(),
            ],
        ]);
    }

    public function handoff(ConversationHandoffRequest $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageHandoffs, $tenant);

        $conversationModel = $this->findConversation($tenant, $conversation);
        $actor = $request->user();
        $assignee = $this->resolveAssignee(
            $tenant,
            $request->integer('assigned_to_user_id') ?: $actor?->getKey(),
        );

        try {
            $conversationModel = $this->service->handoff(
                $conversationModel,
                $actor,
                $assignee,
                $request->validated('reason'),
            );
        } catch (ConversationOperationException $exception) {
            return $this->operationError($exception);
        }

        return response()->json([
            'data' => $this->serializeConversationDetail($conversationModel),
        ]);
    }

    public function assign(ConversationAssignRequest $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageHandoffs, $tenant);

        $conversationModel = $this->findConversation($tenant, $conversation);
        $actor = $request->user();
        $assignee = $this->resolveAssignee($tenant, (int) $request->validated('assigned_to_user_id'));

        try {
            $conversationModel = $this->service->assign($conversationModel, $actor, $assignee);
        } catch (ConversationOperationException $exception) {
            return $this->operationError($exception);
        }

        return response()->json([
            'data' => $this->serializeConversationDetail($conversationModel),
        ]);
    }

    public function manualReply(ConversationManualReplyRequest $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ReplyToConversations, $tenant);

        $conversationModel = $this->findConversation($tenant, $conversation);

        try {
            $message = $this->service->manualReply(
                $conversationModel,
                $request->user(),
                (string) $request->validated('body'),
            );
        } catch (ConversationOperationException $exception) {
            return $this->operationError($exception);
        }

        return response()->json([
            'data' => $this->serializeMessage($message),
        ], Response::HTTP_CREATED);
    }

    public function resume(ConversationResumeRequest $request, int $conversation): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        $this->authorizeForTenant($request, Permission::ManageHandoffs, $tenant);

        $conversationModel = $this->findConversation($tenant, $conversation);
        $targetStatus = ConversationStatus::from((string) $request->validated('target_status'));

        try {
            $conversationModel = $this->service->resume(
                $conversationModel,
                $request->user(),
                $targetStatus,
            );
        } catch (ConversationOperationException $exception) {
            return $this->operationError($exception);
        }

        return response()->json([
            'data' => $this->serializeConversationDetail($conversationModel),
        ]);
    }

    protected function tenantFromRequest(Request $request): Tenant
    {
        /** @var TenantContext $context */
        $context = $request->attributes->get(TenantContext::class);

        return $context->tenant;
    }

    protected function authorizeForTenant(Request $request, Permission $permission, Tenant $tenant): void
    {
        Gate::forUser($request->user())->authorize($permission->value, $tenant);
    }

    protected function findConversation(Tenant $tenant, int $conversationId): Conversation
    {
        return Conversation::query()
            ->forTenant($tenant->getKey())
            ->where('source', ConversationSource::WhatsApp->value)
            ->findOrFail($conversationId);
    }

    protected function resolveAssignee(Tenant $tenant, ?int $assigneeId): User
    {
        $query = User::query()
            ->whereKey($assigneeId)
            ->whereHas('tenantMemberships', function (Builder $builder) use ($tenant): void {
                $builder
                    ->where('tenant_id', $tenant->getKey())
                    ->whereIn('role', [
                        TenantRole::TenantAdmin->value,
                        TenantRole::Operator->value,
                    ]);
            });

        return $query->firstOrFail();
    }

    protected function operationError(ConversationOperationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
        ], $exception->status());
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function availableOperators(Tenant $tenant)
    {
        return TenantUser::query()
            ->forTenant($tenant->getKey())
            ->with('user:id,name,email')
            ->whereIn('role', [TenantRole::TenantAdmin->value, TenantRole::Operator->value])
            ->orderBy('role')
            ->orderBy('user_id')
            ->get()
            ->filter(fn (TenantUser $membership): bool => $membership->user !== null)
            ->map(fn (TenantUser $membership): array => [
                'user_id' => $membership->user->getKey(),
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role?->value,
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeConversationSummary(Conversation $conversation): array
    {
        return [
            'id' => $conversation->getKey(),
            'contact_name' => $conversation->contact_name,
            'contact_phone' => $conversation->contact_phone,
            'status' => $conversation->status->value,
            'assigned_to_user' => $conversation->assignedToUser ? [
                'id' => $conversation->assignedToUser->getKey(),
                'name' => $conversation->assignedToUser->name,
                'email' => $conversation->assignedToUser->email,
            ] : null,
            'whatsapp_line' => $conversation->whatsappLine ? [
                'id' => $conversation->whatsappLine->getKey(),
                'name' => $conversation->whatsappLine->name,
                'display_phone_number' => $conversation->whatsappLine->display_phone_number,
            ] : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_customer_message_at' => $conversation->last_customer_message_at?->toIso8601String(),
            'last_message_preview' => $conversation->getAttribute('last_message_preview'),
            'last_message_direction' => $conversation->getAttribute('last_message_direction'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeConversationDetail(Conversation $conversation): array
    {
        $conversation->loadMissing(['assignedToUser:id,name,email', 'whatsappLine:id,name,display_phone_number', 'state', 'handoffRequests']);
        $latestHandoff = $conversation->handoffRequests->sortByDesc('id')->first();

        return [
            'id' => $conversation->getKey(),
            'contact_name' => $conversation->contact_name,
            'contact_phone' => $conversation->contact_phone,
            'status' => $conversation->status->value,
            'assigned_to_user' => $conversation->assignedToUser ? [
                'id' => $conversation->assignedToUser->getKey(),
                'name' => $conversation->assignedToUser->name,
                'email' => $conversation->assignedToUser->email,
            ] : null,
            'whatsapp_line' => $conversation->whatsappLine ? [
                'id' => $conversation->whatsappLine->getKey(),
                'name' => $conversation->whatsappLine->name,
                'display_phone_number' => $conversation->whatsappLine->display_phone_number,
            ] : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_customer_message_at' => $conversation->last_customer_message_at?->toIso8601String(),
            'state' => $conversation->state ? [
                'current_intent' => $conversation->state->current_intent,
                'last_agent_action' => $conversation->state->last_agent_action,
                'collected_data' => $conversation->state->collected_data,
                'memory_summary' => $conversation->state->memory_summary,
                'expires_at' => $conversation->state->expires_at?->toIso8601String(),
            ] : null,
            'latest_handoff' => $latestHandoff ? [
                'id' => $latestHandoff->getKey(),
                'status' => $latestHandoff->status,
                'reason' => $latestHandoff->reason,
                'assigned_to_user_id' => $latestHandoff->assigned_to_user_id,
                'accepted_at' => $latestHandoff->accepted_at?->toIso8601String(),
                'resolved_at' => $latestHandoff->resolved_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMessage(ConversationMessage $message): array
    {
        $payload = $message->payload ?? [];

        return [
            'id' => $message->getKey(),
            'direction' => $message->direction->value,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
            'error_message' => $message->error_message,
            'source' => data_get($payload, 'context.source') ?? ($payload['source'] ?? null),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'received_at' => $message->received_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
