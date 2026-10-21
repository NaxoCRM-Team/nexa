<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\Custom\Tools\Conversation\SmsDispatchWorker;
final class DispatchWhatsApp implements JobDataLess
{
    public function __construct(private InjectableFactory $factory) {}
    public function run(): void { $this->factory->createWith(SmsDispatchWorker::class,['channel'=>'whatsapp'])->run(); }
}
