<?php
declare(strict_types=1);
namespace Espo\Custom\Hooks\InboundEmail;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Custom\Tools\Conversation\ConversationReceipt;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RelateOptions;

/** @implements AfterRelate<Entity> */
final class ConversationMembership implements AfterRelate
{
    public static int $order = 30;
    public function __construct(private ConversationReceipt $receipt) {}
    public function afterRelate(Entity $entity, string $relationName, Entity $relatedEntity, array $columnData, RelateOptions $options): void
    {
        if ($relationName === 'emails' && $relatedEntity->getEntityType() === 'Email') $this->receipt->process($relatedEntity->getId());
    }
}
