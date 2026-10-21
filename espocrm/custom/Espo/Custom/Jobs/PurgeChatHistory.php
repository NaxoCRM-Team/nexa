<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Conversation\ChatPrivacyService;
final class PurgeChatHistory implements JobDataLess
{
    public function __construct(private ChatPrivacyService $service) {}
    public function run(): void { $this->service->run(); }
}
