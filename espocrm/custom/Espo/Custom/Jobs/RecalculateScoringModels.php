<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Scoring\ScoringService;

final class RecalculateScoringModels implements JobDataLess
{
    public function __construct(private ScoringService $scoring) {}
    public function run(): void { $this->scoring->processDue(); }
}
