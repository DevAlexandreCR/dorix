<?php

namespace App\Providers;

use App\Domain\Agent\AgentRuntime;
use App\Domain\Agent\Contracts\AgentRuntimeInterface;
use App\Domain\Agent\Contracts\LlmProviderInterface;
use App\Domain\Agent\OpenAIResponsesLlmProvider;
use App\Domain\Conversations\CacheConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationLockManager;
use App\Domain\Conversations\Contracts\ConversationResolver;
use App\Domain\Conversations\Contracts\ConversationStateRepository;
use App\Domain\Conversations\Contracts\ConversationStatusTransitioner;
use App\Domain\Conversations\ConversationStatusManager;
use App\Domain\Conversations\DatabaseConversationResolver;
use App\Domain\Conversations\EloquentConversationStateRepository;
use App\Enums\Permission;
use App\Domain\WhatsApp\Contracts\OutboundMessageSender;
use App\Domain\WhatsApp\Contracts\WhatsAppLineResolver;
use App\Domain\WhatsApp\Contracts\WhatsAppWebhookHandler;
use App\Domain\WhatsApp\DatabaseWhatsAppLineResolver;
use App\Domain\WhatsApp\MetaGraphOutboundMessageSender;
use App\Domain\WhatsApp\MetaWhatsAppWebhookHandler;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantAccess;
use App\Support\Tenancy\Contracts\TenantContextResolver;
use App\Support\Tenancy\RequestTenantContextResolver;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContextManager::class);
        $this->app->bind(AgentRuntimeInterface::class, AgentRuntime::class);
        $this->app->bind(LlmProviderInterface::class, OpenAIResponsesLlmProvider::class);
        $this->app->bind(ConversationResolver::class, DatabaseConversationResolver::class);
        $this->app->bind(ConversationStateRepository::class, EloquentConversationStateRepository::class);
        $this->app->bind(ConversationLockManager::class, CacheConversationLockManager::class);
        $this->app->bind(ConversationStatusTransitioner::class, ConversationStatusManager::class);
        $this->app->bind(TenantContextResolver::class, RequestTenantContextResolver::class);
        $this->app->bind(WhatsAppLineResolver::class, DatabaseWhatsAppLineResolver::class);
        $this->app->bind(WhatsAppWebhookHandler::class, MetaWhatsAppWebhookHandler::class);
        $this->app->bind(OutboundMessageSender::class, MetaGraphOutboundMessageSender::class);
        $this->app->singleton(TenantAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $tenantAccess = $this->app->make(TenantAccess::class);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (User $user, ?Tenant $tenant = null) use ($tenantAccess, $permission): bool {
                return $tenantAccess->allows($user, $permission, $tenant);
            });
        }
    }
}
