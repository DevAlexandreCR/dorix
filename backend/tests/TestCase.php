<?php

namespace Tests;

use App\Domain\Connectors\GoogleCalendar\FakeGoogleCalendarClient;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function withCsrf(): static
    {
        return $this
            ->withSession(['_token' => 'test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }

    /**
     * Swaps the container's GoogleCalendarClient binding (real by default,
     * see AppServiceProvider) for a fresh FakeGoogleCalendarClient, and
     * returns it so the test can seed busy intervals / assert recorded
     * calls. Tests that exercise GoogleCalendarClientResolver's sandbox
     * path resolve FakeGoogleCalendarClient::class directly, which this
     * also overrides — see GoogleCalendarClientResolver's docblock.
     */
    protected function fakeGoogleCalendar(): FakeGoogleCalendarClient
    {
        $fake = new FakeGoogleCalendarClient();

        $this->app->instance(GoogleCalendarClient::class, $fake);
        $this->app->instance(FakeGoogleCalendarClient::class, $fake);

        return $fake;
    }
}
