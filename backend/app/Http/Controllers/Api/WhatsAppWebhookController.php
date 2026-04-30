<?php

namespace App\Http\Controllers\Api;

use App\Domain\WhatsApp\Contracts\WhatsAppWebhookHandler;
use App\Domain\WhatsApp\Exceptions\InvalidWhatsAppWebhookPayloadException;
use App\Domain\WhatsApp\Exceptions\WhatsAppLineNotFoundException;
use App\Support\Http\ApiException;
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
            throw new ApiException(
                'webhook_verification_request_invalid',
                'api.webhook.verification_request_invalid',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        if (! is_scalar($challenge) || (string) $challenge === '') {
            throw new ApiException(
                'webhook_challenge_required',
                'api.webhook.challenge_required',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
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
                'reason' => $exception->translatedMessage(),
            ]);
            throw $exception;
        } catch (WhatsAppLineNotFoundException $exception) {
            Log::warning('Rejected WhatsApp webhook because line resolution failed.', [
                'reason' => $exception->translatedMessage(),
            ]);
            throw $exception;
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
