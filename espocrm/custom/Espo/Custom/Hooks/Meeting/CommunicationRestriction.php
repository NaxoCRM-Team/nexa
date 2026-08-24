<?php

namespace Espo\Custom\Hooks\Meeting;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Custom\Tools\Contact\CommunicationRestrictionGuard;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Meeting> */
final class CommunicationRestriction implements BeforeSave
{
    public static int $order = 5;

    public function __construct(private CommunicationRestrictionGuard $guard) {}

    /** @param Meeting $entity */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getStatus() !== 'Planned') {
            return;
        }

        $contactIds = $entity->get('contactsIds');
        if (is_array($contactIds)) {
            $this->guard->assertContactsAllowed($contactIds, 'email');
        }
    }
}
