<?php

namespace App\Domain\WhatsApp\Exceptions;

/**
 * Raised when a post-exchange Graph provisioning step fails: WABA
 * subscription, phone number registration, or the initial line name
 * lookup. The message identifies the specific step that failed.
 */
class EmbeddedSignupProvisioningFailedException extends EmbeddedSignupException
{
}
