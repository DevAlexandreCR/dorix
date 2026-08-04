<?php

namespace App\Domain\WhatsApp;

use App\Domain\WhatsApp\DTO\EchoMessageData;
use App\Domain\WhatsApp\DTO\InboundMessageData;
use App\Domain\WhatsApp\DTO\NormalizedWebhookPayload;
use App\Domain\WhatsApp\DTO\StatusUpdateData;
use App\Domain\WhatsApp\Exceptions\InvalidWhatsAppWebhookPayloadException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class MetaWebhookPayloadNormalizer
{
    public function normalize(array $payload): NormalizedWebhookPayload
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.invalid_object',
                status: 422,
            );
        }

        $entries = $payload['entry'] ?? null;

        if (! is_array($entries) || $entries === []) {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.missing_entry',
                status: 422,
            );
        }

        $inboundMessages = [];
        $statusUpdates = [];
        $echoes = [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? null;

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $field = $change['field'] ?? null;

                if ($field === 'smb_message_echoes') {
                    foreach ($this->normalizeEchoes($change['value'] ?? null) as $echo) {
                        $echoes[] = $echo;
                    }

                    continue;
                }

                if ($field !== 'messages') {
                    // `history` and `smb_app_state_sync` are coexistence fields
                    // Dorix does not process (design.md decision D6); any other
                    // field is equally tolerated. Meta retries on non-2xx, so
                    // unknown fields must not raise an error.
                    Log::info('Ignored WhatsApp webhook field.', [
                        'field' => $field,
                    ]);

                    continue;
                }

                $value = $change['value'] ?? null;

                if (! is_array($value)) {
                    throw new InvalidWhatsAppWebhookPayloadException(
                        'invalid_whatsapp_webhook_payload',
                        'api.webhook.payload.missing_change_value',
                        status: 422,
                    );
                }

                $messages = $value['messages'] ?? [];
                $statuses = $value['statuses'] ?? [];

                if (! is_array($messages) || ! is_array($statuses)) {
                    throw new InvalidWhatsAppWebhookPayloadException(
                        'invalid_whatsapp_webhook_payload',
                        'api.webhook.payload.messages_statuses_array',
                        status: 422,
                    );
                }

                if ($messages === [] && $statuses === []) {
                    continue;
                }

                $phoneNumberId = data_get($value, 'metadata.phone_number_id');

                if (! is_string($phoneNumberId) || $phoneNumberId === '') {
                    throw new InvalidWhatsAppWebhookPayloadException(
                        'invalid_whatsapp_webhook_payload',
                        'api.webhook.payload.missing_phone_number_id',
                        status: 422,
                    );
                }

                $contactNames = $this->extractContactNames($value['contacts'] ?? []);

                foreach ($messages as $message) {
                    $inboundMessages[] = $this->normalizeInboundMessage(
                        phoneNumberId: $phoneNumberId,
                        message: $message,
                        contactNames: $contactNames,
                    );
                }

                foreach ($statuses as $status) {
                    $statusUpdates[] = $this->normalizeStatusUpdate(
                        phoneNumberId: $phoneNumberId,
                        status: $status,
                    );
                }
            }
        }

        // No trailing "nothing was found" guard here: a payload made up
        // entirely of tolerated fields (`history`, `smb_app_state_sync`, or
        // anything unrecognized) legitimately normalizes to zero inbound
        // messages, status updates, and echoes, and design.md decision D6
        // requires that to resolve as a 200, not a 422.
        return new NormalizedWebhookPayload($inboundMessages, $statusUpdates, $echoes);
    }

    /**
     * @return array<int, EchoMessageData>
     */
    protected function normalizeEchoes(mixed $value): array
    {
        if (! is_array($value)) {
            Log::info('Ignored malformed smb_message_echoes webhook value.');

            return [];
        }

        $phoneNumberId = data_get($value, 'metadata.phone_number_id');
        $echoMessages = $value['message_echoes'] ?? [];

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_array($echoMessages)) {
            Log::info('Ignored malformed smb_message_echoes webhook value.');

            return [];
        }

        $contactNames = $this->extractContactNames($value['contacts'] ?? []);

        $echoes = [];

        foreach ($echoMessages as $echoMessage) {
            $echo = $this->normalizeEchoMessage($phoneNumberId, $echoMessage, $contactNames);

            if ($echo !== null) {
                $echoes[] = $echo;
            }
        }

        return $echoes;
    }

    /**
     * @param  array<string, mixed>  $echoMessage
     * @param  array<string, string>  $contactNames
     */
    protected function normalizeEchoMessage(string $phoneNumberId, mixed $echoMessage, array $contactNames): ?EchoMessageData
    {
        if (! is_array($echoMessage)) {
            return null;
        }

        $providerMessageId = $echoMessage['id'] ?? null;
        $contactPhone = $echoMessage['to'] ?? null;
        $providerMessageType = $echoMessage['type'] ?? null;
        $timestamp = $echoMessage['timestamp'] ?? null;

        if (
            ! is_string($providerMessageId) || $providerMessageId === ''
            || ! is_string($contactPhone) || $contactPhone === ''
            || ! is_string($providerMessageType) || $providerMessageType === ''
            || ! is_scalar($timestamp) || (string) $timestamp === ''
        ) {
            Log::info('Ignored malformed smb_message_echoes entry.');

            return null;
        }

        $body = $providerMessageType === 'text'
            ? data_get($echoMessage, 'text.body')
            : null;

        return new EchoMessageData(
            phoneNumberId: $phoneNumberId,
            providerMessageId: $providerMessageId,
            contactPhone: $contactPhone,
            contactName: $contactNames[$contactPhone] ?? null,
            messageType: $providerMessageType === 'text' ? 'text' : 'unsupported',
            body: is_string($body) ? $body : null,
            providerMessageType: $providerMessageType,
            payload: [
                'message' => $echoMessage,
                'contact' => [
                    'wa_id' => $contactPhone,
                    'name' => $contactNames[$contactPhone] ?? null,
                ],
            ],
            sentAt: CarbonImmutable::createFromTimestampUTC((int) $timestamp),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @return array<string, string>
     */
    protected function extractContactNames(array $contacts): array
    {
        $names = [];

        foreach ($contacts as $contact) {
            $waId = $contact['wa_id'] ?? null;
            $name = data_get($contact, 'profile.name');

            if (is_string($waId) && $waId !== '' && is_string($name) && $name !== '') {
                $names[$waId] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $contactNames
     */
    protected function normalizeInboundMessage(string $phoneNumberId, array $message, array $contactNames): InboundMessageData
    {
        $providerMessageId = $message['id'] ?? null;
        $contactPhone = $message['from'] ?? null;
        $providerMessageType = $message['type'] ?? null;
        $timestamp = $message['timestamp'] ?? null;

        if (! is_string($providerMessageId) || $providerMessageId === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.inbound_message_id_required',
                status: 422,
            );
        }

        if (! is_string($contactPhone) || $contactPhone === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.inbound_from_required',
                status: 422,
            );
        }

        if (! is_string($providerMessageType) || $providerMessageType === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.inbound_type_required',
                status: 422,
            );
        }

        if (! is_scalar($timestamp) || (string) $timestamp === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.inbound_timestamp_required',
                status: 422,
            );
        }

        $body = $providerMessageType === 'text'
            ? data_get($message, 'text.body')
            : null;

        if ($providerMessageType === 'text' && (! is_string($body) || $body === '')) {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.inbound_text_body_required',
                status: 422,
            );
        }

        return new InboundMessageData(
            phoneNumberId: $phoneNumberId,
            providerMessageId: $providerMessageId,
            contactPhone: $contactPhone,
            contactName: $contactNames[$contactPhone] ?? null,
            messageType: $providerMessageType === 'text' ? 'text' : 'unsupported',
            body: is_string($body) ? $body : null,
            providerMessageType: $providerMessageType,
            payload: [
                'message' => $message,
                'contact' => [
                    'wa_id' => $contactPhone,
                    'name' => $contactNames[$contactPhone] ?? null,
                ],
            ],
            receivedAt: CarbonImmutable::createFromTimestampUTC((int) $timestamp),
        );
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function normalizeStatusUpdate(string $phoneNumberId, array $status): StatusUpdateData
    {
        $providerMessageId = $status['id'] ?? null;
        $state = $status['status'] ?? null;
        $timestamp = $status['timestamp'] ?? null;

        if (! is_string($providerMessageId) || $providerMessageId === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.status_id_required',
                status: 422,
            );
        }

        if (! is_string($state) || $state === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.status_required',
                status: 422,
            );
        }

        if (! is_scalar($timestamp) || (string) $timestamp === '') {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.status_timestamp_required',
                status: 422,
            );
        }

        $errors = $status['errors'] ?? [];

        if (! is_array($errors)) {
            throw new InvalidWhatsAppWebhookPayloadException(
                'invalid_whatsapp_webhook_payload',
                'api.webhook.payload.status_errors_array',
                status: 422,
            );
        }

        return new StatusUpdateData(
            phoneNumberId: $phoneNumberId,
            providerMessageId: $providerMessageId,
            status: $state,
            errors: array_values(array_filter($errors, 'is_array')),
            payload: $status,
            occurredAt: CarbonImmutable::createFromTimestampUTC((int) $timestamp),
        );
    }
}
