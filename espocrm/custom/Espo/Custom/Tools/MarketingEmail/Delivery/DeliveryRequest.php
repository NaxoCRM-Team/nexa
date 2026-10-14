<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

use InvalidArgumentException;

final readonly class DeliveryRequest
{
    /** @param array<string, string> $headers @param list<DeliveryAttachment> $attachments */
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
        public array $attachments = [],
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
        if (!array_is_list($attachments) || count($attachments) > 10) {
            throw new InvalidArgumentException('A marketing email can contain at most 10 attachments.');
        }
        $total = 0;
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof DeliveryAttachment) {
                throw new InvalidArgumentException('Marketing attachments must be validated MIME parts.');
            }
            $total += strlen($attachment->contents);
        }
        if ($total > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Marketing attachments cannot exceed 10 MB in total.');
        }
    }
}
