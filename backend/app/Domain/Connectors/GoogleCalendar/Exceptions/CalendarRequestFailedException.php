<?php

namespace App\Domain\Connectors\GoogleCalendar\Exceptions;

use RuntimeException;

/**
 * A transient/infra failure talking to Google Calendar (5xx, network
 * error, unexpected response shape) — NOT a broken connection. Unlike
 * CalendarConnectionUnavailableException, this does not imply the
 * refresh token is invalid, so callers must NOT mark the connection as
 * broken when they catch this.
 */
class CalendarRequestFailedException extends RuntimeException
{
}
