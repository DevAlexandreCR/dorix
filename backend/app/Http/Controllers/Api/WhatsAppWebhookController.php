<?php

namespace App\Http\Controllers\Api;

use App\Domain\WhatsApp\Contracts\WhatsAppWebhookHandler;
use App\Domain\WhatsApp\Exceptions\InvalidWhatsAppWebhookPayloadException;
use App\Domain\WhatsApp\Exceptions\WhatsAppLineNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController
{
    public function __construct(
        protected WhatsAppWebhookHandler $handler,
    ) {
    }

    public function verify(Request $request): Response|JsonResponse
    {
        $query = $request->query();

        $mode = $query['hub.mode'] ?? $query['hub_mode'] ?? null;
        $token = $query['hub.verify_token'] ?? $query['hub_verify_token'] ?? null;
        $challenge = $query['hub.challenge'] ?? $query['hub_challenge'] ?? null;

        if ($mode !== 'subscribe' || ! is_string($token) || $token !== config('services.whatsapp.meta.webhook_verify_token')) {
            return response()->json([
                'message' => 'Invalid webhook verification request.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! is_scalar($challenge) || (string) $challenge === '') {
            return response()->json([
                'message' => 'The webhook challenge is required.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response((string) $challenge, Response::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            $result = $this->handler->handle($request->all());
        } catch (InvalidWhatsAppWebhookPayloadException $exception) {
            Log::warning('Rejected WhatsApp webhook payload.', [
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (WhatsAppLineNotFoundException $exception) {
            Log::warning('Rejected WhatsApp webhook because line resolution failed.', [
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => 'accepted',
            'received_messages' => $result->receivedMessages,
            'deduplicated_messages' => $result->deduplicatedMessages,
            'status_updates' => $result->statusUpdates,
            'jobs_dispatched' => $result->jobsDispatched,
        ]);
    }
}
