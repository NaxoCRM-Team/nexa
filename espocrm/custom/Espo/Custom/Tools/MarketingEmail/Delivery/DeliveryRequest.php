<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

use InvalidArgumentException;

final readonly class DeliveryRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $recipient,
        public string $fromAddress,
        public ?string $fromName,
        public ?string $replyToAddress,
        public string $subject,
        public string $htmlBody,
        public ?string $textBody,
        public string $queueItemId,
        public array $headers = [],
    ) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid recipient email address is required.');
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid sender email address is required.');
        }
        if ($replyToAddress !== null && !filter_var($replyToAddress, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid reply-to email address is required.');
        }
        if (trim($subject) === '' || trim($htmlBody) === '' || trim($queueItemId) === '') {
            throw new InvalidArgumentException('Subject, HTML content and queue item ID are required.');
        }
    }
}
