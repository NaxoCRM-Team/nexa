<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Event\EventRetentionService;

/** Applies the active tenant's behavior-event policy in bounded daily batches. */
final class PurgeBehaviorEvents implements JobDataLess
{
    public function __construct(private EventRetentionService $service) {}

    public function run(): void
    {
        $this->service->purgeScheduled();
    }
}
