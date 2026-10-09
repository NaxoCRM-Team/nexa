<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Automation\WorkflowService;

final class ProcessWorkflows implements JobDataLess
{
    public function __construct(private WorkflowService $workflows) {}
    public function run(): void {$this->workflows->processDue();}
}
