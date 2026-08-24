<?php

namespace Espo\Custom\Hooks\Email;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Custom\Tools\Contact\CommunicationRestrictionGuard;
use Espo\Entities\Email;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Email> */
final class CommunicationRestriction implements BeforeSave
{
    public static int $order = 5;

    public function __construct(private CommunicationRestrictionGuard $guard) {}

    /** @param Email $entity */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getStatus() === Email::STATUS_SENDING) {
            $this->guard->assertEmailRecipientsAllowed($entity);
        }
    }
}
