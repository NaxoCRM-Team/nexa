<?php

namespace Espo\Custom\Tools\Customer;

use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Projects native CRM writes into Nexa's customer coordination tables.
 *
 * Contact, Account and activity records remain authoritative in their native
 * tables. This recorder only adds lifecycle history, relationship history and
 * durable events for cross-module automation and reporting.
 */
final class CustomerFoundationRecorder
{
    private const CUSTOMER_TYPES = ['Contact', 'Account'];
    private const ACTIVITY_TYPES = ['Note', 'Task', 'Meeting', 'Call', 'Email', 'Document'];

    /** @var array<string, array{name: string, category: string, position: int}> */
    private const LIFECYCLE_STAGES = [
        'unassigned' => ['name' => 'Unassigned', 'category' => 'inactive', 'position' => 0],
        'subscriber' => ['name' => 'Subscriber', 'category' => 'new', 'position' => 10],
        'lead' => ['name' => 'Lead', 'category' => 'active', 'position' => 20],
        'marketing-qualified-lead' => ['name' => 'Marketing Qualified Lead', 'category' => 'qualified', 'position' => 30],
        'sales-qualified-lead' => ['name' => 'Sales Qualified Lead', 'category' => 'qualified', 'position' => 40],
        'opportunity' => ['name' => 'Opportunity', 'category' => 'qualified', 'position' => 50],
        'customer' => ['name' => 'Customer', 'category' => 'customer', 'position' => 60],
        'evangelist' => ['name' => 'Evangelist', 'category' => 'customer', 'position' => 70],
        'other' => ['name' => 'Other', 'category' => 'active', 'position' => 80],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
    ) {}

    public function afterSave(Entity $entity): void
    {
        $type = $entity->getEntityType();
        $isCustomer = in_array($type, self::CUSTOMER_TYPES, true);
        $isActivity = in_array($type, self::ACTIVITY_TYPES, true);
        if (!$isCustomer && !$isActivity) {
            return;
        }
        $context = $this->writeContext();
        if (!$context) {
            return;
        }

        if ($isCustomer) {
            $this->recordCustomerSave($entity, $context);
            return;
        }
        $this->recordActivitySave($entity, $context);
    }

    public function afterRemove(Entity $entity): void
    {
        $type = $entity->getEntityType();
        if (!in_array($type, array_merge(self::CUSTOMER_TYPES, self::ACTIVITY_TYPES), true)) {
            return;
        }
        $context = $this->writeContext();
        if (!$context) {
            return;
        }

        $event = strtolower($type) . '.deleted';
        $correlationId = $this->uuid();
        $this->audit($context, $event, $type, $entity->getId(), $correlationId, []);
        $this->outbox($context, $event, $type, $entity->getId(), $correlationId, []);

        if (in_array($type, self::CUSTOMER_TYPES, true)) {
            $this->closeRelationships($context, $type, $entity->getId());
        }
    }

    public function relationshipChanged(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        bool $related,
    ): void {
        if (!$this->involvesCustomer($entity, $relatedEntity)) {
            return;
        }
        $context = $this->writeContext();
        if (!$context) {
            return;
        }

        [$source, $target] = in_array($entity->getEntityType(), self::CUSTOMER_TYPES, true)
            ? [$entity, $relatedEntity]
            : [$relatedEntity, $entity];
        $this->persistRelationship($context, $source, $target, $relationName, $related);

        // Activities can be linked after their initial save. Upserting the
        // timeline subject here makes that native relationship visible to
        // automation without copying the activity record.
        $activity = in_array($entity->getEntityType(), self::ACTIVITY_TYPES, true) ? $entity
            : (in_array($relatedEntity->getEntityType(), self::ACTIVITY_TYPES, true) ? $relatedEntity : null);
        $customer = $activity === $entity ? $relatedEntity : $entity;
        if ($related && $activity && in_array($customer->getEntityType(), self::CUSTOMER_TYPES, true)) {
            $this->recordActivityTimeline($activity, $context, $customer);
        }
    }

    private function recordCustomerSave(Entity $entity, TenantContext $context): void
    {
        $type = $entity->getEntityType();
        $action = strtolower($type) . ($entity->isNew() ? '.created' : '.updated');
        $correlationId = $this->uuid();
        $metadata = ['changedFields' => $this->changedFields($entity)];

        $this->syncContactIdentities($entity, $context);
        $this->syncPrimaryRelationship($entity, $context);
        $this->syncLifecycle($entity, $context, $correlationId);
        $this->audit($context, $action, $type, $entity->getId(), $correlationId, $metadata);
        $this->outbox($context, $action, $type, $entity->getId(), $correlationId, $metadata);

        if ($entity->isNew()) {
            $this->timeline(
                $context,
                $action,
                $type,
                $entity->getId(),
                $type === 'Contact' ? $entity->getId() : null,
                $type === 'Account' ? $entity->getId() : null,
                $correlationId,
                $type . ' created'
            );
        }
    }

    private function recordActivitySave(Entity $entity, TenantContext $context): void
    {
        $type = $entity->getEntityType();
        $action = strtolower($type) . ($entity->isNew() ? '.created' : '.updated');
        $correlationId = $this->uuid();
        $metadata = ['changedFields' => $this->changedFields($entity)];
        $this->audit($context, $action, $type, $entity->getId(), $correlationId, $metadata);
        $this->outbox($context, $action, $type, $entity->getId(), $correlationId, $metadata);

        if ($entity->isNew()) {
            $this->recordActivityTimeline($entity, $context);
        }
    }

    private function syncLifecycle(Entity $entity, TenantContext $context, string $correlationId): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('lifecycleStage')) {
            return;
        }

        $type = $entity->getEntityType();
        $stageKey = $this->stageKey((string) $entity->get('lifecycleStage'));
        $definitionId = $this->deterministicUuid("{$context->tenantId}:lifecycle:{$type}");
        $stageId = $this->deterministicUuid("{$definitionId}:{$stageKey}");
        $assignmentId = $this->deterministicUuid("{$definitionId}:{$type}:{$entity->getId()}");
        $stage = self::LIFECYCLE_STAGES[$stageKey] ?? self::LIFECYCLE_STAGES['other'];
        $pdo = $this->entityManager->getPDO();

        $statement = $pdo->prepare(
            'INSERT INTO nexa_lifecycle_definition ' .
            '(id, tenant_id, definition_key, name, entity_type, is_default) VALUES (?, ?, ?, ?, ?, 1) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1'
        );
        $statement->execute([
            $definitionId,
            $context->tenantId,
            strtolower($type) . '_customer_lifecycle',
            $type . ' customer lifecycle',
            $type,
        ]);

        $statement = $pdo->prepare(
            'INSERT INTO nexa_lifecycle_stage ' .
            '(id, tenant_id, lifecycle_definition_id, stage_key, name, category, position) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), ' .
            'category = VALUES(category), is_active = 1'
        );
        $statement->execute([
            $stageId, $context->tenantId, $definitionId, $stageKey,
            $stage['name'], $stage['category'], $stage['position'],
        ]);

        $select = $pdo->prepare(
            'SELECT lifecycle_stage_id FROM nexa_lifecycle_assignment ' .
            'WHERE tenant_id = ? AND lifecycle_definition_id = ? AND entity_type = ? AND entity_id = ? LIMIT 1'
        );
        $select->execute([$context->tenantId, $definitionId, $type, $entity->getId()]);
        $fromStageId = $select->fetchColumn();
        if ($fromStageId === $stageId) {
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO nexa_lifecycle_assignment ' .
            '(id, tenant_id, lifecycle_definition_id, lifecycle_stage_id, entity_type, entity_id) ' .
            'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ' .
            'lifecycle_stage_id = VALUES(lifecycle_stage_id), entered_at = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([
            $assignmentId, $context->tenantId, $definitionId, $stageId, $type, $entity->getId(),
        ]);

        $transitionId = $this->uuid();
        $statement = $pdo->prepare(
            'INSERT INTO nexa_lifecycle_transition ' .
            '(id, tenant_id, lifecycle_assignment_id, from_stage_id, to_stage_id, source, actor_type, actor_id, correlation_id) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $transitionId, $context->tenantId, $assignmentId, $fromStageId ?: null, $stageId,
            'crm-record-hook', $this->actorType(), $this->actorId(), $correlationId,
        ]);

        $event = strtolower($type) . '.lifecycle.changed';
        $metadata = ['fromStageId' => $fromStageId ?: null, 'toStage' => $stageKey];
        $this->timeline(
            $context,
            $event,
            'LifecycleTransition',
            $transitionId,
            $type === 'Contact' ? $entity->getId() : null,
            $type === 'Account' ? $entity->getId() : null,
            $correlationId,
            $type . ' lifecycle changed',
            $metadata
        );
        $this->audit($context, $event, $type, $entity->getId(), $correlationId, $metadata);
        $this->outbox($context, $event, $type, $entity->getId(), $correlationId, $metadata);
    }

    private function syncPrimaryRelationship(Entity $entity, TenantContext $context): void
    {
        $type = $entity->getEntityType();
        $attribute = $type === 'Contact' ? 'accountId' : 'parentAccountId';
        if (!$entity->isNew() && !$entity->isAttributeChanged($attribute)) {
            return;
        }

        $oldId = trim((string) $entity->getFetched($attribute));
        $newId = trim((string) $entity->get($attribute));
        $relationName = $type === 'Contact' ? 'primary_account' : 'parent_account';

        if ($oldId !== '' && $oldId !== $newId) {
            $oldTarget = $this->entityManager->getRDBRepository('Account')->getById($oldId);
            if ($oldTarget) {
                $this->persistRelationship($context, $entity, $oldTarget, $relationName, false);
            }
        }

        if ($newId !== '') {
            $newTarget = $this->entityManager->getRDBRepository('Account')->getById($newId);
            if ($newTarget) {
                $this->persistRelationship($context, $entity, $newTarget, $relationName, true);
            }
        }
    }

    private function syncContactIdentities(Entity $entity, TenantContext $context): void
    {
        if ($entity->getEntityType() !== 'Contact') {
            return;
        }

        foreach (['email' => 'emailAddress', 'phone' => 'phoneNumber'] as $identityType => $field) {
            if (!$entity->isNew() && !$entity->isAttributeChanged($field)) {
                continue;
            }

            $value = strtolower(trim((string) $entity->get($field)));
            if ($identityType === 'phone') {
                $value = preg_replace('/[^0-9+]/', '', $value) ?? '';
            }
            if ($value === '') {
                continue;
            }

            $hash = hash('sha256', $value);
            $statement = $this->entityManager->getPDO()->prepare(
                'INSERT IGNORE INTO nexa_identity_link ' .
                '(id, tenant_id, contact_id, identity_type, normalized_value_hash, verification_status, is_primary) ' .
                "VALUES (?, ?, ?, ?, ?, 'unverified', 1)"
            );
            $statement->execute([
                $this->deterministicUuid("{$context->tenantId}:{$identityType}:{$hash}"),
                $context->tenantId,
                $entity->getId(),
                $identityType,
                $hash,
            ]);
        }
    }

    private function persistRelationship(
        TenantContext $context,
        Entity $source,
        Entity $target,
        string $relationName,
        bool $related,
    ): void {
        $sourceType = $source->getEntityType();
        $targetType = $target->getEntityType();
        $typeKey = substr($this->key("{$sourceType}_{$relationName}_{$targetType}"), 0, 64);
        $typeId = $this->deterministicUuid("{$context->tenantId}:relationship-type:{$typeKey}");
        $edgeId = $this->deterministicUuid(
            "{$context->tenantId}:edge:{$typeId}:{$sourceType}:{$source->getId()}:{$targetType}:{$target->getId()}"
        );
        $pdo = $this->entityManager->getPDO();

        $statement = $pdo->prepare(
            'INSERT INTO nexa_relationship_type ' .
            '(id, tenant_id, type_key, label, source_entity_type, target_entity_type) ' .
            'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = 1'
        );
        $statement->execute([
            $typeId, $context->tenantId, $typeKey,
            ucwords(str_replace(['_', '-'], ' ', $relationName)), $sourceType, $targetType,
        ]);

        if ($related) {
            $statement = $pdo->prepare(
                'INSERT INTO nexa_relationship_edge ' .
                '(id, tenant_id, relationship_type_id, source_entity_type, source_entity_id, ' .
                'target_entity_type, target_entity_id, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE deleted_at = NULL, updated_at = CURRENT_TIMESTAMP(6), ' .
                'created_by_id = VALUES(created_by_id)'
            );
            $statement->execute([
                $edgeId, $context->tenantId, $typeId, $sourceType, $source->getId(),
                $targetType, $target->getId(), $this->actorId(),
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE nexa_relationship_edge SET deleted_at = CURRENT_TIMESTAMP(6), ' .
                'updated_at = CURRENT_TIMESTAMP(6) WHERE id = ? AND tenant_id = ?'
            );
            $statement->execute([$edgeId, $context->tenantId]);
        }

        $verb = $related ? 'related' : 'unrelated';
        $event = 'customer.relationship.' . $verb;
        $correlationId = $this->uuid();
        $metadata = [
            'relationship' => $relationName,
            'sourceType' => $sourceType,
            'sourceId' => $source->getId(),
            'targetType' => $targetType,
            'targetId' => $target->getId(),
        ];
        [$contactId, $accountId] = $this->relationshipSubjects($source, $target);
        $this->timeline(
            $context,
            $event,
            'RelationshipChange',
            $correlationId,
            $contactId,
            $accountId,
            $correlationId,
            "{$sourceType} {$verb} to {$targetType}",
            $metadata
        );
        $this->audit($context, $event, $sourceType, $source->getId(), $correlationId, $metadata);
        $this->outbox($context, $event, $sourceType, $source->getId(), $correlationId, $metadata);
    }

    private function recordActivityTimeline(
        Entity $activity,
        TenantContext $context,
        ?Entity $explicitCustomer = null,
    ): void {
        $contactId = null;
        $accountId = null;
        if ($explicitCustomer) {
            $contactId = $explicitCustomer->getEntityType() === 'Contact' ? $explicitCustomer->getId() : null;
            $accountId = $explicitCustomer->getEntityType() === 'Account' ? $explicitCustomer->getId() : null;
        } else {
            $parentType = (string) $activity->get('parentType');
            $parentId = trim((string) $activity->get('parentId'));
            $contactId = $parentType === 'Contact' ? $parentId : null;
            $accountId = $parentType === 'Account' ? $parentId : null;
        }

        if ($contactId && !$accountId) {
            $statement = $this->entityManager->getPDO()->prepare(
                'SELECT account_id FROM contact WHERE id = ? AND tenant_id = ? AND service_id = ? LIMIT 1'
            );
            $statement->execute([$contactId, $context->tenantId, $context->serviceId]);
            $value = $statement->fetchColumn();
            $accountId = is_string($value) && $value !== '' ? $value : null;
        }
        if (!$contactId && !$accountId) {
            return;
        }

        $type = $activity->getEntityType();
        $event = strtolower($type) . '.created';
        $this->timeline(
            $context,
            $event,
            $type,
            $activity->getId(),
            $contactId,
            $accountId,
            null,
            $type . ' recorded',
            [],
            true
        );
    }

    private function closeRelationships(TenantContext $context, string $type, string $id): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_relationship_edge SET deleted_at = CURRENT_TIMESTAMP(6), ' .
            'updated_at = CURRENT_TIMESTAMP(6) WHERE tenant_id = ? AND deleted_at IS NULL ' .
            'AND ((source_entity_type = ? AND source_entity_id = ?) ' .
            'OR (target_entity_type = ? AND target_entity_id = ?))'
        );
        $statement->execute([$context->tenantId, $type, $id, $type, $id]);
    }

    /** @param array<string, mixed> $metadata */
    private function timeline(
        TenantContext $context,
        string $eventType,
        string $sourceType,
        string $sourceId,
        ?string $contactId,
        ?string $accountId,
        ?string $correlationId,
        string $summary,
        array $metadata = [],
        bool $mergeSubject = false,
    ): void {
        if (!$contactId && !$accountId) {
            return;
        }

        $sql = 'INSERT INTO nexa_timeline_event ' .
            '(id, tenant_id, contact_id, account_id, event_type, source_entity_type, source_entity_id, ' .
            'source_occurred_at, actor_type, actor_id, correlation_id, summary, metadata_json) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6), ?, ?, ?, ?, ?)';
        if ($mergeSubject) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' .
                'contact_id = COALESCE(contact_id, VALUES(contact_id)), ' .
                'account_id = COALESCE(account_id, VALUES(account_id)), ' .
                'summary = VALUES(summary), metadata_json = VALUES(metadata_json)';
        }

        $statement = $this->entityManager->getPDO()->prepare($sql);
        $statement->execute([
            $this->uuid(), $context->tenantId, $contactId, $accountId, $eventType,
            $sourceType, $sourceId, $this->actorType(), $this->actorId(), $correlationId,
            $summary, $this->json($metadata),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        TenantContext $context,
        string $action,
        string $subjectType,
        string $subjectId,
        string $correlationId,
        array $metadata,
    ): void {
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_audit_event ' .
            '(id, tenant_id, service_id, actor_type, actor_user_id, action, subject_type, subject_id, ' .
            'correlation_id, source, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $this->actorType(),
            $this->actorId(), $action, $subjectType, $subjectId, $correlationId,
            'crm-record-hook', $this->json($metadata),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function outbox(
        TenantContext $context,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        array $metadata,
    ): void {
        $payload = [
            'tenantId' => $context->tenantId,
            'serviceId' => $context->serviceId,
            'aggregateType' => $aggregateType,
            'aggregateId' => $aggregateId,
            'actorId' => $this->actorId(),
            'metadata' => $metadata,
        ];
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_outbox_event ' .
            '(id, tenant_id, service_id, event_type, aggregate_type, aggregate_id, payload_json, correlation_id) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $eventType,
            $aggregateType, $aggregateId, $this->json($payload), $correlationId,
        ]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function relationshipSubjects(Entity $source, Entity $target): array
    {
        $contactId = null;
        $accountId = null;
        foreach ([$source, $target] as $entity) {
            $contactId = $entity->getEntityType() === 'Contact' ? $entity->getId() : $contactId;
            $accountId = $entity->getEntityType() === 'Account' ? $entity->getId() : $accountId;
        }

        return [$contactId, $accountId];
    }

    private function involvesCustomer(Entity $left, Entity $right): bool
    {
        return in_array($left->getEntityType(), self::CUSTOMER_TYPES, true) ||
            in_array($right->getEntityType(), self::CUSTOMER_TYPES, true);
    }

    private function writeContext(): ?TenantContext
    {
        $context = $this->tenantContextStore->current();
        if ($context) {
            return $context;
        }

        // Explicit platform execution is reserved for audited migrations and
        // repair work. Ordinary CRM writes without a trusted tenant fail.
        return $this->tenantContextStore->isPlatform()
            ? null
            : $this->tenantContextStore->require();
    }

    /** @return string[] */
    private function changedFields(Entity $entity): array
    {
        $fields = [];
        foreach ($entity->getAttributeList() as $attribute) {
            if ($entity->isAttributeChanged($attribute)) {
                $fields[] = $attribute;
            }
            if (count($fields) >= 100) {
                break;
            }
        }

        sort($fields);
        return $fields;
    }

    private function stageKey(string $stage): string
    {
        $key = $this->key($stage);
        return isset(self::LIFECYCLE_STAGES[$key]) ? $key : ($key === '' ? 'unassigned' : 'other');
    }

    private function key(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', trim($value)) ?? '';
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '', '-');
    }

    private function actorType(): string
    {
        return $this->user->getId() === 'system' ? 'system' : 'user';
    }

    private function actorId(): ?string
    {
        $id = trim((string) $this->user->getId());
        return $id !== '' ? $id : null;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function deterministicUuid(string $value): string
    {
        $hex = hash('sha256', 'nexa-customer-foundation:' . $value);
        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3),
            substr($hex, 17, 3), substr($hex, 20, 12)
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
