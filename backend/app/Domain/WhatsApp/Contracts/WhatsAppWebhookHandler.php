<?php

namespace App\Domain\WhatsApp\Contracts;

use App\Domain\WhatsApp\DTO\WebhookHandlingResult;

interface WhatsAppWebhookHandler
{
    public function handle(array $payload): WebhookHandlingResult;
}
