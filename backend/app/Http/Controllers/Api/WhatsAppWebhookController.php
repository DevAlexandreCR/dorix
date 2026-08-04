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
        $configuredToken = config('services.whatsapp.meta.webhook_verify_token');

        if ($mode !== 'subscribe' || ! is_string($token) || ! is_string($configuredToken) || ! hash_equals($configuredToken, $token)) {
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
        $this->verifySignature($request);

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

    /**
     * Fail-closed signature verification (design.md decision D5): a missing
     * app secret or a missing/invalid X-Hub-Signature-256 header must both
     * reject the request before it reaches the handler.
     */
    protected function verifySignature(Request $request): void
    {
        $appSecret = config('services.whatsapp.meta.app_secret');

        if (! is_string($appSecret) || $appSecret === '') {
            Log::warning('Rejected WhatsApp webhook because META_APP_SECRET is not configured.');

            throw new ApiException(
                'webhook_signature_verification_unavailable',
                'api.webhook.signature_verification_unavailable',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $signatureHeader = $request->header('X-Hub-Signature-256');
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! is_string($signatureHeader) || ! hash_equals($expectedSignature, $signatureHeader)) {
            Log::warning('Rejected WhatsApp webhook because the signature is missing or invalid.');

            throw new ApiException(
                'webhook_signature_invalid',
                'api.webhook.signature_invalid',
                status: Response::HTTP_FORBIDDEN,
            );
        }
    }
}
