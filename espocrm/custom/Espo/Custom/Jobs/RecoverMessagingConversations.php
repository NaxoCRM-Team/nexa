<?php
declare(strict_types=1);
namespace Espo\Custom\Jobs;
use Espo\Core\Job\JobDataLess;
use Espo\Custom\Tools\Conversation\MessagingConversationProjector;
use Espo\Custom\Tools\Conversation\IncomingMediaService;
final class RecoverMessagingConversations implements JobDataLess
{
    public function __construct(private MessagingConversationProjector $projector,private IncomingMediaService $media) {}
    public function run(): void { $this->projector->recover();$this->media->recover(); }
}
