<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Automation\WorkflowService;
use Espo\Custom\Tools\Automation\WorkflowWebhookService;

final class ProcessWorkflows implements JobDataLess
{
    public function __construct(private WorkflowService $workflows,private WorkflowWebhookService $webhooks) {}
    public function run(): void {$this->workflows->processAutomatic(); $this->workflows->processDue(); $this->webhooks->process();}
}
