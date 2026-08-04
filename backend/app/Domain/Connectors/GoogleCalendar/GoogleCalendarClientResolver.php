<?php

namespace App\Domain\Connectors\GoogleCalendar;

use App\Enums\ConversationSource;
use Illuminate\Contracts\Container\Container;

/**
 * Chooses between the real HTTP-backed GoogleCalendarClient and
 * FakeGoogleCalendarClient based on where the conversation came from —
 * NOT based on the app environment.
 *
 * GoogleCalendarClient::class is bound to HttpGoogleCalendarClient by
 * default (see AppServiceProvider); tests swap the binding via
 * Tests\TestCase::fakeGoogleCalendar(). The agent sandbox
 * (ConversationSource::AgentSandbox) is production code exercised by
 * real admins against the same container as real WhatsApp traffic, so it
 * cannot rely on the container's default binding alone to stay fake.
 *
 * Scheduling tools (task 4.x: get_service_details, check_availability,
 * create_appointment) MUST resolve their calendar client through this
 * resolver — keyed off the conversation's `source` — instead of
 * type-hinting GoogleCalendarClient directly in their constructor. That
 * is what makes "the sandbox uses FakeGoogleCalendarClient" (task 6.1)
 * true and reachable even though sandbox and real conversations are
 * served by the same PHP-FPM worker/container.
 */
class GoogleCalendarClientResolver
{
    public function __construct(
        protected Container $container,
    ) {
    }

    public function forConversationSource(ConversationSource $source): GoogleCalendarClient
    {
        if ($source === ConversationSource::AgentSandbox) {
            return $this->container->make(FakeGoogleCalendarClient::class);
        }

        return $this->container->make(GoogleCalendarClient::class);
    }
}
