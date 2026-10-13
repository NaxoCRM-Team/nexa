<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

final readonly class DeliveryResult
{
    public function __construct(
        public string $providerMessageId,
        public ?string $providerToken,
        public string $status = 'submitted',
        public ?int $providerRecordId = null,
    ) {}
}
