<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Customer;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;
use Throwable;

/** Moves Nexa projections after the native merger transfers standard CRM data. */
final class TenantRecordMergeService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
    ) {}

    /** @param string[] $sourceIdList @param callable(): void $nativeMerge */
    public function merge(string $entityType, string $targetId, array $sourceIdList, callable $nativeMerge): void
    {
        $context = $this->tenantContextStore->require();
        $sourceIdList = array_values(array_unique(array_filter(
            $sourceIdList,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));

        if ($sourceIdList === [] || in_array($targetId, $sourceIdList, true)) {
            throw new BadRequest('Choose one surviving record and at least one different record to merge.');
        }

        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'nexa_record_merge';
        $ownsTransaction ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT {$savepoint}");

        try {
            $nativeMerge();

            foreach ($sourceIdList as $sourceId) {
                $this->moveCustomValues($pdo, $context, $entityType, $targetId, $sourceId);
                $this->moveLifecycle($pdo, $context, $entityType, $targetId, $sourceId);
                $this->moveTimeline($pdo, $context, $entityType, $targetId, $sourceId);
                $this->moveRelationshipRows($pdo, $context, 'nexa_relationship_edge', 'relationship_type_id', $entityType, $targetId, $sourceId, false);
                $this->moveRelationshipRows($pdo, $context, 'nexa_custom_relationship_link', 'relationship_definition_id', $entityType, $targetId, $sourceId, true);

                if ($entityType === 'Contact') {
                    $this->moveContactData($pdo, $context, $targetId, $sourceId);
                }
            }

            $this->audit($pdo, $context, $entityType, $targetId, $sourceIdList);
            $ownsTransaction ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }
            throw $error;
        }
    }

    private function moveCustomValues(PDO $pdo, TenantContext $context, string $entityType, string $targetId, string $sourceId): void
    {
        // The survivor wins when both records define the same custom property.
        $statement = $pdo->prepare(
            'DELETE source FROM nexa_custom_field_value source INNER JOIN nexa_custom_field_value target ' .
            'ON target.tenant_id = source.tenant_id AND target.service_id = source.service_id ' .
            'AND target.field_definition_id = source.field_definition_id AND target.entity_type = source.entity_type ' .
            'AND target.entity_id = ? WHERE source.tenant_id = ? AND source.service_id = ? ' .
            'AND source.entity_type = ? AND source.entity_id = ?'
        );
        $statement->execute([$targetId, $context->tenantId, $context->serviceId, $entityType, $sourceId]);

        $statement = $pdo->prepare(
            'UPDATE nexa_custom_field_value SET entity_id = ?, updated_by_id = ?, updated_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE tenant_id = ? AND service_id = ? AND entity_type = ? AND entity_id = ?'
        );
        $statement->execute([$targetId, $this->user->getId(), $context->tenantId, $context->serviceId, $entityType, $sourceId]);
    }

    private function moveLifecycle(PDO $pdo, TenantContext $context, string $entityType, string $targetId, string $sourceId): void
    {
        $statement = $pdo->prepare(
            'SELECT id, lifecycle_definition_id FROM nexa_lifecycle_assignment ' .
            'WHERE tenant_id = ? AND entity_type = ? AND entity_id = ? FOR UPDATE'
        );
        $statement->execute([$context->tenantId, $entityType, $sourceId]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $targetStatement = $pdo->prepare(
                'SELECT id FROM nexa_lifecycle_assignment WHERE tenant_id = ? AND lifecycle_definition_id = ? ' .
                'AND entity_type = ? AND entity_id = ? FOR UPDATE'
            );
            $targetStatement->execute([$context->tenantId, $assignment['lifecycle_definition_id'], $entityType, $targetId]);
            $targetAssignmentId = $targetStatement->fetchColumn();

            if ($targetAssignmentId !== false) {
                $move = $pdo->prepare(
                    'UPDATE nexa_lifecycle_transition SET lifecycle_assignment_id = ? ' .
                    'WHERE tenant_id = ? AND lifecycle_assignment_id = ?'
                );
                $move->execute([$targetAssignmentId, $context->tenantId, $assignment['id']]);
                $delete = $pdo->prepare('DELETE FROM nexa_lifecycle_assignment WHERE tenant_id = ? AND id = ?');
                $delete->execute([$context->tenantId, $assignment['id']]);
            } else {
                $move = $pdo->prepare(
                    'UPDATE nexa_lifecycle_assignment SET entity_id = ?, updated_at = CURRENT_TIMESTAMP(6) ' .
                    'WHERE tenant_id = ? AND id = ?'
                );
                $move->execute([$targetId, $context->tenantId, $assignment['id']]);
            }
        }
    }

    private function moveTimeline(PDO $pdo, TenantContext $context, string $entityType, string $targetId, string $sourceId): void
    {
        $column = $entityType === 'Contact' ? 'contact_id' : 'account_id';
        $statement = $pdo->prepare("UPDATE nexa_timeline_event SET {$column} = ? WHERE tenant_id = ? AND {$column} = ?");
        $statement->execute([$targetId, $context->tenantId, $sourceId]);
    }

    private function moveContactData(PDO $pdo, TenantContext $context, string $targetId, string $sourceId): void
    {
        foreach (['nexa_identity_link', 'nexa_communication_preference', 'nexa_call_session'] as $table) {
            $serviceClause = $table === 'nexa_identity_link' ? '' : ' AND service_id = ?';
            $parameters = [$targetId, $context->tenantId];
            if ($serviceClause !== '') {
                $parameters[] = $context->serviceId;
            }
            $parameters[] = $sourceId;
            $statement = $pdo->prepare("UPDATE {$table} SET contact_id = ? WHERE tenant_id = ?{$serviceClause} AND contact_id = ?");
            $statement->execute($parameters);
        }
    }

    private function moveRelationshipRows(
        PDO $pdo,
        TenantContext $context,
        string $table,
        string $definitionColumn,
        string $entityType,
        string $targetId,
        string $sourceId,
        bool $serviceScoped,
    ): void {
        $serviceClause = $serviceScoped ? ' AND service_id = ?' : '';
        $parameters = [$context->tenantId];
        if ($serviceScoped) {
            $parameters[] = $context->serviceId;
        }
        array_push($parameters, $entityType, $sourceId, $entityType, $sourceId);
        $statement = $pdo->prepare(
            "SELECT * FROM {$table} WHERE tenant_id = ?{$serviceClause} AND " .
            '((source_entity_type = ? AND source_entity_id = ?) OR (target_entity_type = ? AND target_entity_id = ?)) FOR UPDATE'
        );
        $statement->execute($parameters);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $newSourceId = $row['source_entity_type'] === $entityType && $row['source_entity_id'] === $sourceId ? $targetId : $row['source_entity_id'];
            $newTargetId = $row['target_entity_type'] === $entityType && $row['target_entity_id'] === $sourceId ? $targetId : $row['target_entity_id'];

            if ($row['source_entity_type'] === $row['target_entity_type'] && $newSourceId === $newTargetId) {
                $this->deleteRelationshipRow($pdo, $context, $table, $row['id'], $serviceScoped);
                continue;
            }

            $duplicateParameters = [$context->tenantId];
            if ($serviceScoped) {
                $duplicateParameters[] = $context->serviceId;
            }
            array_push($duplicateParameters, $row[$definitionColumn], $row['source_entity_type'], $newSourceId, $row['target_entity_type'], $newTargetId, $row['id']);
            $duplicate = $pdo->prepare(
                "SELECT id FROM {$table} WHERE tenant_id = ?{$serviceClause} AND {$definitionColumn} = ? " .
                'AND source_entity_type = ? AND source_entity_id = ? AND target_entity_type = ? AND target_entity_id = ? AND id <> ? LIMIT 1'
            );
            $duplicate->execute($duplicateParameters);

            if ($duplicate->fetchColumn() !== false) {
                $this->deleteRelationshipRow($pdo, $context, $table, $row['id'], $serviceScoped);
                continue;
            }

            $updateParameters = [$newSourceId, $newTargetId, $context->tenantId];
            if ($serviceScoped) {
                $updateParameters[] = $context->serviceId;
            }
            $updateParameters[] = $row['id'];
            $update = $pdo->prepare(
                "UPDATE {$table} SET source_entity_id = ?, target_entity_id = ? WHERE tenant_id = ?{$serviceClause} AND id = ?"
            );
            $update->execute($updateParameters);
        }
    }

    private function deleteRelationshipRow(PDO $pdo, TenantContext $context, string $table, string $id, bool $serviceScoped): void
    {
        $serviceClause = $serviceScoped ? ' AND service_id = ?' : '';
        $parameters = [$context->tenantId];
        if ($serviceScoped) {
            $parameters[] = $context->serviceId;
        }
        $parameters[] = $id;
        $statement = $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = ?{$serviceClause} AND id = ?");
        $statement->execute($parameters);
    }

    /** @param string[] $sourceIdList */
    private function audit(PDO $pdo, TenantContext $context, string $entityType, string $targetId, array $sourceIdList): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO nexa_audit_event (id, tenant_id, service_id, actor_type, actor_user_id, action, ' .
            "subject_type, subject_id, source, metadata_json) VALUES (?, ?, ?, 'user', ?, 'crm.record.merged', ?, ?, 'native-merge', ?)"
        );
        $statement->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $this->user->getId(), $entityType, $targetId,
            json_encode(['sourceIds' => $sourceIdList], JSON_THROW_ON_ERROR),
        ]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
