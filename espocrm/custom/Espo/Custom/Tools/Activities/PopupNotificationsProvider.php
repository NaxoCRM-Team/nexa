<?php

namespace Espo\Custom\Tools\Activities;

use DateInterval;
use DateTime;
use Espo\Core\Name\Field;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Entities\Notification;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\Modules\Crm\Entities\Reminder;
use Espo\Modules\Crm\Entities\Task;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\Tools\PopupNotification\Item;
use Espo\Tools\PopupNotification\Provider;
use Exception;

/**
 * Replaces Crm's PopupNotificationsProvider (registered via
 * custom/Espo/Custom/Resources/metadata/app/popupNotifications.json). Core's version only
 * surfaces the ephemeral popup toast for a due Popup reminder - it never touches the
 * Notification entity, so the notification bell badge/dropdown never reflects a fired
 * reminder. This adds a one-time Notification per (user, entity) the first time a
 * reminder is surfaced, alongside the unchanged toast behavior.
 */
class PopupNotificationsProvider implements Provider
{
    private const REMINDER_PAST_HOURS = 24;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
    ) {}

    /**
     * @return Item[]
     * @throws Exception
     */
    public function get(User $user): array
    {
        $userId = $user->getId();

        $dt = new DateTime();

        $pastHours = $this->config->get('reminderPastHours', self::REMINDER_PAST_HOURS);

        $now = $dt->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $nowShifted = (clone $dt)
            ->sub(new DateInterval('PT' . $pastHours . 'H'))
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        /** @var iterable<Reminder> $reminderCollection */
        $reminderCollection = $this->entityManager
            ->getRDBRepositoryByClass(Reminder::class)
            ->select([
                'id',
                'entityType',
                'entityId',
            ])
            ->where([
                'type' => Reminder::TYPE_POPUP,
                'userId' => $userId,
                'remindAt<=' => $now,
                'startAt>' => $nowShifted,
            ])
            ->find();

        $resultList = [];

        foreach ($reminderCollection as $reminder) {
            $reminderId = $reminder->getId();
            $entityType = $reminder->getTargetEntityType();
            $entityId = $reminder->getTargetEntityId();

            if (!$entityId || !$entityType) {
                continue;
            }

            $entity = $this->entityManager->getEntityById($entityType, $entityId);

            if (!$entity) {
                continue;
            }

            if (
                $entity instanceof CoreEntity &&
                $entity->hasLinkMultipleField('users') &&
                $entity->hasAttribute('usersColumns')
            ) {
                $status = $entity->getLinkMultipleColumn('users', 'status', $userId);

                if ($status === Meeting::ATTENDEE_STATUS_DECLINED) {
                    $this->removeReminder($reminderId);

                    continue;
                }
            }

            $dateField = $entityType === Task::ENTITY_TYPE ?
                'dateEnd' :
                'dateStart';

            $data = (object) [
                'id' => $entity->getId(),
                'entityType' => $entityType,
                'name' => $entity->get(Field::NAME),
                'dateField' => $dateField,
                'attributes' => (object) [
                    $dateField => $entity->get($dateField),
                    $dateField . 'Date' => $entity->get($dateField . 'Date'),
                ],
            ];

            $resultList[] = new Item($reminderId, $data);

            $this->notifyOnce($userId, $entityType, $entityId, (string) $entity->get(Field::NAME));
        }

        return $resultList;
    }

    private function notifyOnce(string $userId, string $entityType, string $entityId, string $name): void
    {
        $exists = $this->entityManager
            ->getRDBRepository(Notification::ENTITY_TYPE)
            ->where([
                'userId' => $userId,
                'type' => Notification::TYPE_SYSTEM,
                'relatedType' => $entityType,
                'relatedId' => $entityId,
            ])
            ->findOne();

        if ($exists) {
            return;
        }

        $this->entityManager->createEntity(Notification::ENTITY_TYPE, [
            'type' => Notification::TYPE_SYSTEM,
            'userId' => $userId,
            'data' => [
                'message' => "Reminder: '{$name}' is due",
            ],
            'relatedType' => $entityType,
            'relatedId' => $entityId,
        ]);
    }

    private function removeReminder(string $id): void
    {
        $deleteQuery = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from(Reminder::ENTITY_TYPE)
            ->where([Attribute::ID => $id])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($deleteQuery);
    }
}
