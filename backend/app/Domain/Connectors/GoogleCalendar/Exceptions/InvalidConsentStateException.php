<?php

namespace App\Domain\Connectors\GoogleCalendar\Exceptions;

use RuntimeException;

/**
 * The OAuth consent `state` param on the callback was tampered with,
 * malformed, expired, or points to a tenant/line combination that does
 * not match (design D6). No credential is ever persisted when this is
 * thrown.
 */
class InvalidConsentStateException extends RuntimeException
{
}
