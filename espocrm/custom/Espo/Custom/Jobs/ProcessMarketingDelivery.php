<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;
use Espo\Custom\Tools\MarketingEmail\PostalCallbackService;

/** Processes approved Nexa marketing sends inside the scheduled job's tenant context. */
final class ProcessMarketingDelivery implements JobDataLess
{
    public function __construct(private MarketingDispatchService $service, private PostalCallbackService $callbacks) {}

    public function run(): void
    {
        $this->service->runScheduled();
        $this->callbacks->reconcilePending();
    }
}
