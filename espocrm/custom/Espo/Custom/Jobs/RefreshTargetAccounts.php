<?php
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Abm\AbmService;
final class RefreshTargetAccounts implements JobDataLess
{
    public function __construct(private AbmService $service) {}
    public function run(): void{$this->service->processDue();}
}
