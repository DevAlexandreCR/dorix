<?php

namespace App\Domain\Conversations;

use App\Domain\Conversations\Contracts\ConversationResolver;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\WhatsApp\DTO\InboundMessageData;
use App\Domain\WhatsApp\DTO\ResolvedWhatsAppLine;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Models\Conversation;

class DatabaseConversationResolver implements ConversationResolver
{
    public function __construct(
        protected ConversationStateRepository $stateRepository,
        protected ConversationStatusTransitioner $statusTransitioner,
    ) {
    }

    public function resolveForInbound(ResolvedWhatsAppLine $resolvedLine, InboundMessageData $message): Conversation
    {
        $conversation = Conversation::query()
            ->forTenant($resolvedLine->tenantId())
            ->where('whatsapp_line_id', $resolvedLine->line->getKey())
            ->where('contact_phone', $message->contactPhone)
            ->where('source', ConversationSource::WhatsApp->value)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if (! $conversation || $conversation->status === ConversationStatus::Closed) {
            $conversation = Conversation::query()->create([
                'tenant_id' => $resolvedLine->tenantId(),
                'whatsapp_line_id' => $resolvedLine->line->getKey(),
                'contact_phone' => $message->contactPhone,
                'contact_name' => $message->contactName,
                'status' => ConversationStatus::BotActive,
                'source' => ConversationSource::WhatsApp,
                'last_message_at' => $message->receivedAt,
                'last_customer_message_at' => $message->receivedAt,
                'metadata' => [
                    'source' => 'whatsapp_webhook',
                ],
            ]);

            $this->stateRepository->getOrCreate($conversation);

            return $conversation;
        }

        if ($conversation->status === ConversationStatus::WaitingCustomer) {
            $conversation = $this->statusTransitioner->transition($conversation, ConversationStatus::BotActive);
        }

        $this->stateRepository->getOrCreate($conversation);

        return $conversation;
    }
}
