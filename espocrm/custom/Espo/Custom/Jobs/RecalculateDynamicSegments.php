<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

/** Refreshes every active dynamic Target List in the current tenant context. */
final class RecalculateDynamicSegments implements JobDataLess
{
    public function __construct(private SegmentWorkspaceService $service) {}
    public function run(): void { $this->service->recalculateAll(); }
}
