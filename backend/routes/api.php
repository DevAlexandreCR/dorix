<?php

use App\Http\Controllers\Api\AdminAgentConfigController;
use App\Http\Controllers\Api\AdminCredentialController;
use App\Http\Controllers\Api\AdminTenantController;
use App\Http\Controllers\Api\AdminTenantUserController;
use App\Http\Controllers\Api\AdminToolConfigController;
use App\Http\Controllers\Api\AdminWhatsAppLineController;
use App\Http\Controllers\Api\AgentSandboxController;
use App\Http\Controllers\Api\AuthSessionController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\HealthController;
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

    Route::middleware(['web', 'auth:sanctum', 'tenant.context'])->group(function (): void {
        Route::get('/conversations', [OperationalConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [OperationalConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/handoff', [OperationalConversationController::class, 'handoff']);
        Route::post('/conversations/{conversation}/assign', [OperationalConversationController::class, 'assign']);
        Route::post('/conversations/{conversation}/manual-reply', [OperationalConversationController::class, 'manualReply']);
        Route::post('/conversations/{conversation}/resume', [OperationalConversationController::class, 'resume']);
        Route::get('/agent-sandbox/sessions', [AgentSandboxController::class, 'index']);
        Route::post('/agent-sandbox/sessions', [AgentSandboxController::class, 'store']);
        Route::get('/agent-sandbox/sessions/{conversation}', [AgentSandboxController::class, 'show']);
        Route::post('/agent-sandbox/sessions/{conversation}/messages', [AgentSandboxController::class, 'storeMessage']);
        Route::post('/agent-sandbox/sessions/{conversation}/close', [AgentSandboxController::class, 'close']);

        Route::get('/data-sources', [DataSourceController::class, 'index']);
        Route::post('/data-sources', [DataSourceController::class, 'store']);
        Route::post('/data-sources/excel', [DataSourceController::class, 'storeExcel']);
        Route::post('/data-sources/{dataSource}/imports/{import}/retry', [DataSourceController::class, 'retryImport']);

        Route::get('/admin/overview', [AdminTenantController::class, 'overview']);
        Route::post('/admin/tenant-users', [AdminTenantUserController::class, 'store']);
        Route::patch('/admin/tenant-users/{tenantUser}', [AdminTenantUserController::class, 'update']);
        Route::delete('/admin/tenant-users/{tenantUser}', [AdminTenantUserController::class, 'destroy']);
        Route::post('/admin/whatsapp-lines', [AdminWhatsAppLineController::class, 'store']);
        Route::patch('/admin/whatsapp-lines/{whatsappLine}', [AdminWhatsAppLineController::class, 'update']);
        Route::delete('/admin/whatsapp-lines/{whatsappLine}', [AdminWhatsAppLineController::class, 'destroy']);
        Route::put('/admin/agent-configs/tenant', [AdminAgentConfigController::class, 'updateTenant']);
        Route::put('/admin/agent-configs/lines/{whatsappLine}', [AdminAgentConfigController::class, 'updateLine']);
        Route::put('/admin/tool-configs/tenant/{toolName}', [AdminToolConfigController::class, 'updateTenant']);
        Route::put('/admin/tool-configs/lines/{whatsappLine}/{toolName}', [AdminToolConfigController::class, 'updateLine']);
        Route::put('/admin/credentials', [AdminCredentialController::class, 'upsert']);
    });

    Route::middleware(['web', 'auth:sanctum'])->group(function (): void {
        Route::get('/admin/tenants', [AdminTenantController::class, 'index']);
        Route::post('/admin/tenants', [AdminTenantController::class, 'store']);
        Route::patch('/admin/tenants/{tenant}', [AdminTenantController::class, 'update']);
        Route::delete('/admin/tenants/{tenant}', [AdminTenantController::class, 'destroy']);
    });
});
