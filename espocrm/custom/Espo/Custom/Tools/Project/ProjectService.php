<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Project;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Tenant\ServiceEntitlementChecker;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use PDO;
use stdClass;

/** Tenant-owned project coordination around native Tasks and Documents. */
final class ProjectService
{
    private const STATUSES = ['Planned', 'Active', 'On Hold', 'Completed', 'Canceled'];
    private const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    public function __construct(
        private TenantContextStore $tenantContextStore,
        private ServiceEntitlementChecker $entitlements,
        private Acl $acl,
        private EntityManager $entityManager,
        private User $user,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $context = $this->context();
        $this->requireScope(Table::ACTION_READ);
        $params = [$context->tenantId, $context->serviceId];
        $owner = '';
        if (!$this->acl->checkReadAll('Task')) {
            $owner = ' AND p.owner_id = ?';
            $params[] = $this->user->getId();
        }
        $sql = "SELECT p.*, a.name account_name, u.first_name owner_first_name, u.last_name owner_last_name, " .
            "(SELECT COUNT(*) FROM nexa_project_task pt WHERE pt.tenant_id=p.tenant_id AND pt.service_id=p.service_id AND pt.project_id=p.id) task_count, " .
            "(SELECT COUNT(*) FROM nexa_project_document pd WHERE pd.tenant_id=p.tenant_id AND pd.service_id=p.service_id AND pd.project_id=p.id) document_count, " .
            "(SELECT COUNT(*) FROM nexa_project_update pu WHERE pu.tenant_id=p.tenant_id AND pu.service_id=p.service_id AND pu.project_id=p.id) update_count " .
            "FROM nexa_project p LEFT JOIN account a ON a.id=p.account_id AND a.tenant_id=p.tenant_id AND a.service_id=p.service_id " .
            "LEFT JOIN user u ON u.id=p.owner_id AND u.tenant_id=p.tenant_id AND u.service_id=p.service_id " .
            "WHERE p.tenant_id=? AND p.service_id=? AND p.is_archived=0{$owner} ORDER BY FIELD(p.status,'Active','Planned','On Hold','Completed','Canceled'), p.date_end, p.name";
        $statement = $this->entityManager->getPDO()->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'serialize'], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $context = $this->context();
        $this->requireProjectAccess($context, $id, Table::ACTION_READ);
        $project = array_values(array_filter($this->list(), fn (array $item): bool => $item['id'] === $id))[0] ?? null;
        if (!$project) throw new Forbidden('Project is unavailable.');

        $project['tasks'] = $this->linkedRecords($context, $id, 'Task');
        $project['documents'] = $this->linkedRecords($context, $id, 'Document');
        $project['updates'] = $this->updates($context, $id);
        return $project;
    }

    /** @return array{id: string} */
    public function create(stdClass $data): array
    {
        $context = $this->context();
        $this->requireScope(Table::ACTION_CREATE);
        $values = $this->validate($context, $data);
        $id = $this->uuid();
        $sql = 'INSERT INTO nexa_project (id,tenant_id,service_id,name,status,priority,description,date_start,date_end,account_id,owner_id,created_by_id,modified_by_id) ' .
            'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $this->entityManager->getPDO()->prepare($sql)->execute([
            $id, $context->tenantId, $context->serviceId, $values['name'], $values['status'], $values['priority'],
            $values['description'], $values['dateStart'], $values['dateEnd'], $values['accountId'], $values['ownerId'],
            $this->user->getId(), $this->user->getId(),
        ]);
        return ['id' => $id];
    }

    public function update(string $id, stdClass $data): void
    {
        $context = $this->context();
        $this->requireProjectAccess($context, $id, Table::ACTION_EDIT);
        $values = $this->validate($context, $data);
        $sql = 'UPDATE nexa_project SET name=?,status=?,priority=?,description=?,date_start=?,date_end=?,account_id=?,owner_id=?,modified_by_id=? ' .
            'WHERE id=? AND tenant_id=? AND service_id=? AND is_archived=0';
        $this->entityManager->getPDO()->prepare($sql)->execute([
            $values['name'], $values['status'], $values['priority'], $values['description'], $values['dateStart'],
            $values['dateEnd'], $values['accountId'], $values['ownerId'], $this->user->getId(),
            $id, $context->tenantId, $context->serviceId,
        ]);
    }

    public function archive(string $id): void
    {
        $context = $this->context();
        $this->requireProjectAccess($context, $id, Table::ACTION_DELETE);
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_project SET is_archived=1,modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?'
        );
        $statement->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
    }

    /** @return array{id: string} */
    public function addUpdate(string $projectId, stdClass $data): array
    {
        $context = $this->context();
        $this->requireProjectAccess($context, $projectId, Table::ACTION_EDIT);
        $body = trim(strip_tags((string) ($data->body ?? '')));
        if ($body === '' || mb_strlen($body) > 10000) throw new BadRequest('Enter an update up to 10,000 characters.');
        $mentionedIds = $this->validUserIds($context, is_array($data->mentionedUserIds ?? null) ? $data->mentionedUserIds : []);
        $id = $this->uuid();
        $sql = 'INSERT INTO nexa_project_update (id,tenant_id,service_id,project_id,body_html,mentioned_user_ids_json,created_by_id) VALUES (?,?,?,?,?,?,?)';
        $this->entityManager->getPDO()->prepare($sql)->execute([
            $id, $context->tenantId, $context->serviceId, $projectId, $body,
            $mentionedIds ? json_encode($mentionedIds, JSON_THROW_ON_ERROR) : null, $this->user->getId(),
        ]);
        return ['id' => $id];
    }

    public function link(string $projectId, stdClass $data): void
    {
        $context = $this->context();
        $this->requireProjectAccess($context, $projectId, Table::ACTION_EDIT);
        $type = (string) ($data->type ?? '');
        if (!in_array($type, ['Task', 'Document'], true)) throw new BadRequest('Select Task or Document.');
        $recordId = $this->validEntityId($context, $type, $data->recordId ?? null);
        if (!$recordId) throw new BadRequest("Select an available {$type}.");

        $table = $type === 'Task' ? 'nexa_project_task' : 'nexa_project_document';
        $column = $type === 'Task' ? 'task_id' : 'document_id';
        $sql = "INSERT IGNORE INTO {$table} (id,tenant_id,service_id,project_id,{$column}) VALUES (?,?,?,?,?)";
        $this->entityManager->getPDO()->prepare($sql)->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $projectId, $recordId,
        ]);
    }

    public function unlink(string $projectId, string $type, string $recordId): void
    {
        $context = $this->context();
        $this->requireProjectAccess($context, $projectId, Table::ACTION_EDIT);
        if (!in_array($type, ['Task', 'Document'], true)) throw new BadRequest('Select Task or Document.');
        $table = $type === 'Task' ? 'nexa_project_task' : 'nexa_project_document';
        $column = $type === 'Task' ? 'task_id' : 'document_id';
        $sql = "DELETE FROM {$table} WHERE tenant_id=? AND service_id=? AND project_id=? AND {$column}=?";
        $this->entityManager->getPDO()->prepare($sql)->execute([
            $context->tenantId, $context->serviceId, $projectId, $recordId,
        ]);
    }

    /** @return array<string, mixed> */
    private function validate(TenantContext $context, stdClass $data): array
    {
        $name = trim((string) ($data->name ?? ''));
        $status = (string) ($data->status ?? 'Planned');
        $priority = (string) ($data->priority ?? 'Normal');
        if ($name === '' || mb_strlen($name) > 200) throw new BadRequest('Enter a project name up to 200 characters.');
        if (!in_array($status, self::STATUSES, true)) throw new BadRequest('Select a valid project status.');
        if (!in_array($priority, self::PRIORITIES, true)) throw new BadRequest('Select a valid project priority.');
        $start = $this->date($data->dateStart ?? null);
        $end = $this->date($data->dateEnd ?? null);
        if ($start && $end && $end < $start) throw new BadRequest('The project end date must follow its start date.');
        return [
            'name' => $name, 'status' => $status, 'priority' => $priority,
            'description' => trim(strip_tags((string) ($data->description ?? ''))) ?: null,
            'dateStart' => $start, 'dateEnd' => $end,
            'accountId' => $this->validEntityId($context, 'Account', $data->accountId ?? null),
            'ownerId' => $this->validOwnerId($context, $data->ownerId ?? null),
        ];
    }

    private function requireProjectAccess(TenantContext $context, string $id, string $action): void
    {
        $this->requireScope($action);
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT owner_id,created_by_id FROM nexa_project WHERE id=? AND tenant_id=? AND service_id=? AND is_archived=0'
        );
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Forbidden('Project is unavailable.');
        if (!$this->user->isAdmin() && !$this->acl->checkReadAll('Task') &&
            !in_array($this->user->getId(), [$row['owner_id'], $row['created_by_id']], true)) {
            throw new Forbidden('Project is unavailable.');
        }
    }

    private function requireScope(string $action): void
    {
        if (!$this->acl->checkScope('Task', $action)) throw new Forbidden('Project work is unavailable for this role.');
    }

    private function validEntityId(TenantContext $context, string $scope, mixed $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') return null;
        $entity = $this->entityManager->getRDBRepository($scope)->getById($id);
        if (!$entity || !$this->acl->check($entity, Table::ACTION_READ) ||
            $entity->get('tenantId') !== $context->tenantId || $entity->get('serviceId') !== $context->serviceId) {
            throw new BadRequest("Select an available {$scope}.");
        }
        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function linkedRecords(TenantContext $context, string $projectId, string $type): array
    {
        $table = $type === 'Task' ? 'nexa_project_task' : 'nexa_project_document';
        $column = $type === 'Task' ? 'task_id' : 'document_id';
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT {$column} FROM {$table} WHERE tenant_id=? AND service_id=? AND project_id=? ORDER BY created_at DESC"
        );
        $statement->execute([$context->tenantId, $context->serviceId, $projectId]);
        $list = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $recordId) {
            $entity = $this->entityManager->getRDBRepository($type)->getById((string) $recordId);
            if (!$entity || !$this->acl->check($entity, Table::ACTION_READ)) continue;
            $list[] = [
                'id' => $entity->getId(),
                'name' => (string) ($entity->get('name') ?: $type),
                'status' => (string) ($entity->get('status') ?: ''),
                'type' => $type,
            ];
        }
        return $list;
    }

    /** @return list<array<string, mixed>> */
    private function updates(TenantContext $context, string $projectId): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT pu.id,pu.body_html,pu.created_at,u.first_name,u.last_name FROM nexa_project_update pu ' .
            'LEFT JOIN user u ON u.id=pu.created_by_id AND u.tenant_id=pu.tenant_id AND u.service_id=pu.service_id ' .
            'WHERE pu.tenant_id=? AND pu.service_id=? AND pu.project_id=? ORDER BY pu.created_at DESC LIMIT 25'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $projectId]);
        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'body' => $row['body_html'],
            'createdAt' => $row['created_at'],
            'createdByName' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Team member',
        ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function validOwnerId(TenantContext $context, mixed $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') return $this->user->getId();
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT id FROM user WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 AND is_active=1 AND type IN ('regular','admin')"
        );
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        if (!$statement->fetchColumn()) throw new BadRequest('Select an active project owner.');
        return $id;
    }

    /** @param array<mixed> $ids @return list<string> */
    private function validUserIds(TenantContext $context, array $ids): array
    {
        $valid = [];
        foreach (array_values(array_unique(array_map('strval', $ids))) as $id) {
            if ($this->validOwnerId($context, $id) === $id) $valid[] = $id;
        }
        return $valid;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new BadRequest('Enter a valid project date.');
        return $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serialize(array $row): array
    {
        return [
            'id' => $row['id'], 'name' => $row['name'], 'status' => $row['status'], 'priority' => $row['priority'],
            'description' => $row['description'], 'dateStart' => $row['date_start'], 'dateEnd' => $row['date_end'],
            'accountId' => $row['account_id'], 'accountName' => $row['account_name'],
            'ownerId' => $row['owner_id'], 'ownerName' => trim(($row['owner_first_name'] ?? '') . ' ' . ($row['owner_last_name'] ?? '')),
            'taskCount' => (int) $row['task_count'], 'documentCount' => (int) $row['document_count'],
            'updateCount' => (int) $row['update_count'],
        ];
    }

    private function context(): TenantContext
    {
        if (!$this->entitlements->isEnabled('crm')) throw new Forbidden('CRM is not enabled for this workspace.');
        return $this->tenantContextStore->require();
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
            '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}
