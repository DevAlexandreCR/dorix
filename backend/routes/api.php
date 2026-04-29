<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\AuthSessionController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\OperationalConversationController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/webhooks/meta/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/meta/whatsapp', [WhatsAppWebhookController::class, 'handle']);

Route::prefix('v1')->group(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/auth/session', [AuthSessionController::class, 'show']);
        Route::middleware('guest')->post('/auth/login', [AuthSessionController::class, 'store']);
        Route::middleware('auth:sanctum')->post('/auth/logout', [AuthSessionController::class, 'destroy']);
    });

    Route::middleware('tenant.context')->group(function (): void {
        Route::get('/data-sources', [DataSourceController::class, 'index']);
        Route::post('/data-sources/excel', [DataSourceController::class, 'storeExcel']);
        Route::post('/data-sources/{dataSource}/imports/{import}/retry', [DataSourceController::class, 'retryImport']);
    });

    Route::middleware(['web', 'auth:sanctum', 'tenant.context'])->group(function (): void {
        Route::get('/conversations', [OperationalConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [OperationalConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/handoff', [OperationalConversationController::class, 'handoff']);
        Route::post('/conversations/{conversation}/assign', [OperationalConversationController::class, 'assign']);
        Route::post('/conversations/{conversation}/manual-reply', [OperationalConversationController::class, 'manualReply']);
        Route::post('/conversations/{conversation}/resume', [OperationalConversationController::class, 'resume']);
    });
});
