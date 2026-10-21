<?php
declare(strict_types=1);
namespace Espo\Custom\Hooks\NexaChatConversation;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\ORM\{Entity,Repository\Option\RemoveOptions};
use Espo\Custom\Tools\Conversation\ChatPrivacyService;
/** @implements BeforeRemove<Entity> */
final class Privacy implements BeforeRemove
{
    public function __construct(private ChatPrivacyService $privacy) {}
    public function beforeRemove(Entity $entity,RemoveOptions $options): void { $this->privacy->markNativeDeletion($entity); }
}
