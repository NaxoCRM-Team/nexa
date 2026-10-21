<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Conversation\ConversationRoutingService;
final class RouteConversations implements JobDataLess
{
    public function __construct(private ConversationRoutingService $service) {}
    public function run(): void { $this->service->run(); }
}
