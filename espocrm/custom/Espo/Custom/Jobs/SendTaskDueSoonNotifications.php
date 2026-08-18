<?php

namespace Espo\Custom\Jobs;

use DateTime;
use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Notification;
use Espo\Modules\Crm\Entities\Reminder;
use Espo\Modules\Crm\Entities\Task;
use Throwable;

/**
 * For tasks with no reminder configured, notifies the assignee in-app once the
 * task's due date falls within the next 24 hours. Tasks that already have a
 * native Reminder row are left alone — those are handled by Espo's own
 * SubmitPopupReminders/SendEmailReminders jobs.
 *
 * @noinspection PhpUnused
 */
class SendTaskDueSoonNotifications implements JobDataLess
{
    private const LOOKAHEAD_HOURS = 24;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log,
    ) {}

    public function run(): void
    {
        $now = new DateTime();
        $nowStr = $now->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
        $untilStr = (clone $now)
            ->modify('+' . self::LOOKAHEAD_HOURS . ' hours')
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $ignoreStatusList = array_merge(
            $this->metadata->get(['scopes', Task::ENTITY_TYPE, 'completedStatusList']) ?? [],
            $this->metadata->get(['scopes', Task::ENTITY_TYPE, 'canceledStatusList']) ?? []
        );

        $taskList = $this->entityManager
            ->getRDBRepository(Task::ENTITY_TYPE)
            ->where([
                'dateEnd>=' => $nowStr,
                'dateEnd<=' => $untilStr,
                'status!=' => $ignoreStatusList,
                'assignedUserId!=' => null,
            ])
            ->find();

        foreach ($taskList as $task) {
            try {
                $this->processTask($task);
            } catch (Throwable $e) {
                $this->log->error(
                    'Job SendTaskDueSoonNotifications: task ' . $task->getId() .
                    ': [' . $e->getCode() . '] ' . $e->getMessage()
                );
            }
        }
    }

    private function processTask(Task $task): void
    {
        $hasReminder = $this->entityManager
            ->getRDBRepository(Reminder::ENTITY_TYPE)
            ->where([
                'entityType' => Task::ENTITY_TYPE,
                'entityId' => $task->getId(),
            ])
            ->findOne();

        if ($hasReminder) {
            return;
        }

        $alreadyNotified = $this->entityManager
            ->getRDBRepository(Notification::ENTITY_TYPE)
            ->where([
                'type' => Notification::TYPE_SYSTEM,
                'relatedType' => Task::ENTITY_TYPE,
                'relatedId' => $task->getId(),
            ])
            ->findOne();

        if ($alreadyNotified) {
            return;
        }

        $this->entityManager->createEntity(Notification::ENTITY_TYPE, [
            'type' => Notification::TYPE_SYSTEM,
            'userId' => $task->get('assignedUserId'),
            'data' => [
                'message' => "Task '" . $task->get('name') . "' is due soon",
            ],
            'relatedType' => Task::ENTITY_TYPE,
            'relatedId' => $task->getId(),
        ]);
    }
}
