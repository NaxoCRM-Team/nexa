<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Conversation\SmsDispatchWorker;
final class DispatchSms implements JobDataLess
{
    public function __construct(private SmsDispatchWorker $worker) {}
    public function run(): void { $this->worker->run(); }
}
