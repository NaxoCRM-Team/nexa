<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Activity;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Tenant\ServiceEntitlementChecker;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Aggregates native activities without replacing EspoCRM CRUD, recurrence,
 * reminders, attendees or permissions.
 */
final class ActivityWorkspaceService
{
    private const TYPES = ['Task', 'Meeting', 'Call'];

    public function __construct(
        private TenantContextStore $tenantContextStore,
        private ServiceEntitlementChecker $entitlements,
        private Acl $acl,
        private EntityManager $entityManager,
        private User $user,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(?string $from, ?string $to, ?string $ownerId, ?string $type): array
    {
        $context = $this->context();
        [$start, $end] = $this->range($from, $to);
        $type = in_array($type, self::TYPES, true) ? $type : null;
        $ownerId = trim((string) $ownerId) ?: null;
        if ($ownerId && !$this->canReadAll($type)) $ownerId = $this->user->getId();

        $activities = [];
        foreach (self::TYPES as $scope) {
            if (($type && $type !== $scope) || !$this->acl->checkScope($scope, Table::ACTION_READ)) continue;
            array_push($activities, ...$this->activityRows($context, $scope, $start, $end, $ownerId));
        }
        usort($activities, static fn (array $a, array $b): int => strcmp($a['dateStart'], $b['dateStart']));
        return [
            'tenant' => ['id' => $context->tenantId, 'slug' => $context->slug, 'displayName' => $context->displayName],
            'range' => ['from' => $start, 'to' => $end],
            'filters' => ['ownerId' => $ownerId, 'type' => $type],
            'activities' => $activities,
            'owners' => $this->owners($context),
            'metrics' => $this->metrics($activities),
            'permissions' => $this->permissions(),
            'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function range(?string $from, ?string $to): array
    {
        try {
            $start = (new DateTimeImmutable($from ?: 'first day of this month'))->setTime(0, 0);
            $end = (new DateTimeImmutable($to ?: 'last day of next month'))->setTime(23, 59, 59);
        } catch (\Throwable) {
            throw new BadRequest('Enter a valid activity date range.');
        }
        if ($end < $start || $start->diff($end)->days > 370) throw new BadRequest('Activity ranges must cover 1 to 370 days.');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** @return list<array<string, mixed>> */
    private function activityRows(TenantContext $context, string $scope, string $start, string $end, ?string $owner): array
    {
        $table = strtolower($scope);
        $date = match ($scope) {
            'Task' => "COALESCE(date_start, CONCAT(date_start_date, ' 00:00:00'), date_end)",
            'Meeting' => "COALESCE(date_start, CONCAT(date_start_date, ' 00:00:00'))",
            default => 'date_start',
        };
        $sql = "SELECT id, {$date} activity_date FROM `{$table}` " .
            'WHERE tenant_id = :tenant AND service_id = :service AND deleted = 0 ' .
            "AND {$date} BETWEEN :start AND :end";
        $params = ['tenant' => $context->tenantId, 'service' => $context->serviceId, 'start' => $start, 'end' => $end];
        if ($owner) {
            $sql .= ' AND assigned_user_id = :owner';
            $params['owner'] = $owner;
        }
        $statement = $this->entityManager->getPDO()->prepare($sql . " ORDER BY {$date} LIMIT 250");
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            $entity = $this->entityManager->getRDBRepository($scope)->getById((string) $candidate['id']);
            if (!$entity || !$this->acl->check($entity, Table::ACTION_READ)) continue;
            $result[] = $this->serializeActivity($scope, $entity, (string) $candidate['activity_date']);
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function serializeActivity(string $scope, Entity $entity, string $date): array
    {
        return [
            'id' => $entity->getId(),
            'type' => $scope,
            'name' => (string) ($entity->get('name') ?: "Untitled {$scope}"),
            'status' => (string) ($entity->get('status') ?: 'Unassigned'),
            'dateStart' => $date,
            'dateEnd' => $entity->get('dateEnd') ?: $entity->get('dateEndDate'),
            'assignedUserId' => $entity->get('assignedUserId'),
            'assignedUserName' => $entity->get('assignedUserName'),
            'parentId' => $entity->get('parentId'),
            'parentType' => $entity->get('parentType'),
            'parentName' => $entity->get('parentName'),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function owners(TenantContext $context): array
    {
        if (!$this->canReadAll(null)) return [['id' => $this->user->getId(), 'name' => $this->user->getName()]];
        $sql = "SELECT id, COALESCE(NULLIF(TRIM(CONCAT(first_name, ' ', last_name)), ''), user_name) name FROM user " .
            "WHERE tenant_id = ? AND service_id = ? AND deleted = 0 AND is_active = 1 AND type IN ('regular','admin') ORDER BY name";
        $statement = $this->entityManager->getPDO()->prepare($sql);
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string, mixed>> $activities */
    private function metrics(array $activities): array
    {
        $today = date('Y-m-d');
        $nextWeek = date('Y-m-d', strtotime('+7 days'));
        $metrics = ['total' => count($activities), 'today' => 0, 'overdueTasks' => 0, 'upcoming' => 0];
        foreach ($activities as $item) {
            $date = substr((string) $item['dateStart'], 0, 10);
            if ($date === $today) $metrics['today']++;
            if ($date >= $today && $date <= $nextWeek) $metrics['upcoming']++;
            if ($item['type'] === 'Task' && $date < $today && !in_array($item['status'], ['Completed','Canceled','Deferred'], true)) $metrics['overdueTasks']++;
        }
        return $metrics;
    }

    /** @return array<string, bool> */
    private function permissions(): array
    {
        $result = ['readAll' => $this->canReadAll(null)];
        foreach (self::TYPES as $scope) $result[lcfirst($scope) . 'Create'] = $this->acl->checkScope($scope, Table::ACTION_CREATE);
        return $result;
    }

    private function canReadAll(?string $type): bool
    {
        foreach ($type ? [$type] : self::TYPES as $scope) {
            if ($this->acl->checkScope($scope, Table::ACTION_READ) && !$this->acl->checkReadAll($scope)) return false;
        }
        return true;
    }

    private function context(): TenantContext
    {
        if (!$this->entitlements->isEnabled('crm')) throw new Forbidden('CRM is not enabled for this workspace.');
        return $this->tenantContextStore->require();
    }
}
