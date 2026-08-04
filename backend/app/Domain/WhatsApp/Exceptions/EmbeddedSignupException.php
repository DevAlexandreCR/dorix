<?php

namespace App\Domain\WhatsApp\Exceptions;

use RuntimeException;

/**
 * Base for failures during the Meta Embedded Signup flow (code
 * exchange, WABA subscription, phone number registration, initial
 * name lookup). Each concrete subclass carries a translatable message
 * identifying which step failed, so the connect endpoint can surface
 * an accurate 422 without persisting anything.
 */
abstract class EmbeddedSignupException extends RuntimeException
{
}
