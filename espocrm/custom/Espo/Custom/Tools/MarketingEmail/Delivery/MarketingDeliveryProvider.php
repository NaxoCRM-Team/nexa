<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

interface MarketingDeliveryProvider
{
    public function send(DeliveryRequest $request): DeliveryResult;
}
