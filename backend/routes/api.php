<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/webhooks/meta/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/meta/whatsapp', [WhatsAppWebhookController::class, 'handle']);

Route::prefix('v1')->group(function (): void {
    Route::get('/meta', MetaController::class);
});
