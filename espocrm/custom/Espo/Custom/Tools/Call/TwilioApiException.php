<?php

namespace Espo\Custom\Tools\Call;

use RuntimeException;

/** Carries Twilio's own numeric error code so callers can react to specific ones. */
final class TwilioApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $twilioErrorCode)
    {
        parent::__construct($message);
    }
}
