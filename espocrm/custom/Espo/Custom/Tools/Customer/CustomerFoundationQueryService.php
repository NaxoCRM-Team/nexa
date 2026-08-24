<?php

namespace Espo\Custom\Tools\Customer;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Tenant\TenantContextStore;
use Espo\ORM\EntityManager;
use PDO;

/** Reads the coordinated customer view after native record ACL has passed. */
final class CustomerFoundationQueryService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private Acl $acl,
    ) {}

    /** @return array<string, mixed> */
    public function getSnapshot(string $entityType, string $id, int $timelineLimit = 50): array
    {
        $entityType = ucfirst(strtolower(trim($entityType)));
        $id = trim($id);
        if (!in_array($entityType, ['Contact', 'Account'], true)) {
            throw new BadRequest('Customer type must be Contact or Account.');
        }
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
            throw new BadRequest('Invalid customer identifier.');
        }

        $entity = $this->entityManager->getRDBRepository($entityType)->getById($id);
        if (!$entity || !$this->acl->checkEntityRead($entity)) {
            throw new Forbidden('The customer record is not accessible.');
        }

        $context = $this->tenantContextStore->require();
        $contactId = $entityType === 'Contact' ? $id : null;
        $accountId = $entityType === 'Account' ? $id : null;
        $timelineLimit = min(200, max(1, $timelineLimit));

        return [
            'entityType' => $entityType,
            'id' => $id,
            'tenantId' => $context->tenantId,
            'serviceId' => $context->serviceId,
            'identities' => $contactId ? $this->identities($context->tenantId, $contactId) : [],
            'relationships' => $this->relationships($context->tenantId, $entityType, $id),
            'lifecycle' => $this->lifecycle($context->tenantId, $entityType, $id),
            'timeline' => $this->timeline($context->tenantId, $contactId, $accountId, $timelineLimit),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function identities(string $tenantId, string $contactId): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT id, identity_type, provider_key, verification_status, is_primary, verified_at, last_seen_at ' .
            'FROM nexa_identity_link WHERE tenant_id = ? AND contact_id = ? ORDER BY is_primary DESC, created_at ASC'
        );
        $statement->execute([$tenantId, $contactId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function relationships(string $tenantId, string $entityType, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT e.id, t.type_key, t.label, t.inverse_label, e.source_entity_type, e.source_entity_id, ' .
            'e.target_entity_type, e.target_entity_id, e.valid_from, e.valid_until, e.metadata_json, e.updated_at ' .
            'FROM nexa_relationship_edge e INNER JOIN nexa_relationship_type t ' .
            'ON t.id = e.relationship_type_id AND t.tenant_id = e.tenant_id ' .
            'WHERE e.tenant_id = ? AND e.deleted_at IS NULL AND ' .
            '((e.source_entity_type = ? AND e.source_entity_id = ?) ' .
            'OR (e.target_entity_type = ? AND e.target_entity_id = ?)) ORDER BY e.updated_at DESC LIMIT 500'
        );
        $statement->execute([$tenantId, $entityType, $id, $entityType, $id]);
        $visible = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relatedType = $row['source_entity_type'] === $entityType && $row['source_entity_id'] === $id
                ? (string) $row['target_entity_type']
                : (string) $row['source_entity_type'];
            $relatedId = $row['source_entity_type'] === $entityType && $row['source_entity_id'] === $id
                ? (string) $row['target_entity_id']
                : (string) $row['source_entity_id'];

            // Relationship metadata must never reveal a record the caller
            // cannot read through the native tenant and ACL boundary.
            if (!$this->entityManager->hasRepository($relatedType)) {
                continue;
            }
            $related = $this->entityManager->getRDBRepository($relatedType)->getById($relatedId);
            if (!$related || !$this->acl->checkEntityRead($related)) {
                continue;
            }
            $row['relatedEntityType'] = $relatedType;
            $row['relatedEntityId'] = $relatedId;
            $row['metadata'] = $this->decode($row['metadata_json']);
            unset($row['metadata_json']);
            $visible[] = $row;
        }
        return $visible;
    }

    /** @return array{assignment: ?array<string, mixed>, transitions: array<int, array<string, mixed>>} */
    private function lifecycle(string $tenantId, string $entityType, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT a.id, d.definition_key, d.name AS definition_name, s.stage_key, s.name AS stage_name, ' .
            's.category, a.entered_at, a.updated_at FROM nexa_lifecycle_assignment a ' .
            'INNER JOIN nexa_lifecycle_definition d ON d.id = a.lifecycle_definition_id AND d.tenant_id = a.tenant_id ' .
            'INNER JOIN nexa_lifecycle_stage s ON s.id = a.lifecycle_stage_id AND s.tenant_id = a.tenant_id ' .
            'WHERE a.tenant_id = ? AND a.entity_type = ? AND a.entity_id = ? LIMIT 1'
        );
        $statement->execute([$tenantId, $entityType, $id]);
        $assignment = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$assignment) {
            return ['assignment' => null, 'transitions' => []];
        }

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT t.id, fs.stage_key AS from_stage_key, fs.name AS from_stage_name, ' .
            'ts.stage_key AS to_stage_key, ts.name AS to_stage_name, t.reason, t.source, ' .
            't.actor_type, t.actor_id, t.occurred_at, t.metadata_json FROM nexa_lifecycle_transition t ' .
            'LEFT JOIN nexa_lifecycle_stage fs ON fs.id = t.from_stage_id AND fs.tenant_id = t.tenant_id ' .
            'INNER JOIN nexa_lifecycle_stage ts ON ts.id = t.to_stage_id AND ts.tenant_id = t.tenant_id ' .
            'WHERE t.tenant_id = ? AND t.lifecycle_assignment_id = ? ORDER BY t.occurred_at DESC LIMIT 200'
        );
        $statement->execute([$tenantId, $assignment['id']]);
        $transitions = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($transitions as &$transition) {
            $transition['metadata'] = $this->decode($transition['metadata_json']);
            unset($transition['metadata_json']);
        }
        unset($transition);

        return ['assignment' => $assignment, 'transitions' => $transitions];
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline(string $tenantId, ?string $contactId, ?string $accountId, int $limit): array
    {
        $subjectColumn = $contactId ? 'contact_id' : 'account_id';
        $subjectId = $contactId ?: $accountId;
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT id, contact_id, account_id, event_type, source_entity_type, source_entity_id, ' .
            'source_occurred_at, actor_type, actor_id, visibility, correlation_id, summary, metadata_json ' .
            "FROM nexa_timeline_event WHERE tenant_id = ? AND {$subjectColumn} = ? " .
            'ORDER BY source_occurred_at DESC LIMIT ?'
        );
        $statement->bindValue(1, $tenantId);
        $statement->bindValue(2, $subjectId);
        $statement->bindValue(3, $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['metadata'] = $this->decode($row['metadata_json']);
            unset($row['metadata_json']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
