<?php
declare(strict_types=1);
namespace Espo\Custom\Hooks\Email;

use Espo\Core\Hook\Hook\{AfterRelate, AfterSave, BeforeSave};
use Espo\Custom\Tools\Conversation\ConversationReceipt;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\{RelateOptions, SaveOptions};

/** @implements BeforeSave<Entity> @implements AfterSave<Entity> @implements AfterRelate<Entity> */
final class ConversationLifecycle implements BeforeSave, AfterSave, AfterRelate
{
    public static int $order = 30;
    public function __construct(private ConversationReceipt $receipt) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() && $entity->get('status') === 'Archived' && $options->get('isBeingImported')) {
            $entity->set('conversationImportPendingAt', gmdate('Y-m-d H:i:s'));
        }
    }
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('conversationImportPendingAt')) $this->receipt->process($entity->getId());
    }
    public function afterRelate(Entity $entity, string $relationName, Entity $relatedEntity, array $columnData, RelateOptions $options): void
    {
        if ($relationName === 'inboundEmails') $this->receipt->process($entity->getId());
    }
}
