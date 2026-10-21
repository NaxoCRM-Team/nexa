<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Conversation\MessengerRecovery;
final class RecoverMessengerReplies implements JobDataLess
{
    public function __construct(private MessengerRecovery $recovery) {}
    public function run(): void {$this->recovery->run();}
}
