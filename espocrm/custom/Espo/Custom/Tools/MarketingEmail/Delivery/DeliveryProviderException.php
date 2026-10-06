<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

use RuntimeException;

final class DeliveryProviderException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, private bool $submissionUncertain = false)
    {
        parent::__construct($message, $code, $previous);
    }

    public function isSubmissionUncertain(): bool
    {
        return $this->submissionUncertain;
    }
}
