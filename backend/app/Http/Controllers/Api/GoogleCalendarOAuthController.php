<?php

namespace App\Http\Controllers\Api;

use App\Domain\Connectors\GoogleCalendar\Exceptions\CalendarRequestFailedException;
use App\Domain\Connectors\GoogleCalendar\Exceptions\InvalidConsentStateException;
use App\Domain\Connectors\GoogleCalendar\GoogleCalendarOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public OAuth callback for the Google Calendar consent flow
 * (design D6) — registered with no auth/tenant middleware, like the
 * WhatsApp webhook routes, since Google calls this directly and the
 * tenant/line are carried in the signed `state` param rather than in
 * any auth context on the request.
 */
class GoogleCalendarOAuthController
{
    public function __construct(
        protected GoogleCalendarOAuthService $oauth,
    ) {
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = $request->query('code');

        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            return $this->redirectToPanel('error');
        }

        try {
            $this->oauth->completeConnection($state, $code);
        } catch (InvalidConsentStateException|CalendarRequestFailedException $exception) {
            Log::warning('Rejected Google Calendar OAuth callback.', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->redirectToPanel('error');
        }

        return $this->redirectToPanel('success');
    }

    protected function redirectToPanel(string $result): RedirectResponse
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/admin/connect/lines?calendar_connection='.$result;

        return redirect()->away($url);
    }
}
