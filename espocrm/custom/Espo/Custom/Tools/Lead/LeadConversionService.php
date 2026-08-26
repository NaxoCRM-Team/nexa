<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Lead;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;
use stdClass;
use Throwable;

/** Preserves Nexa-owned customer history while the native converter owns CRM records. */
final class LeadConversionService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
    ) {}

    /** @param callable(): stdClass $nativeConvert */
    public function convert(string $leadId, callable $nativeConvert): stdClass
    {
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $snapshot = $this->leadSnapshot($pdo, $context, $leadId);

        if (($snapshot['status'] ?? null) === 'Converted') {
            throw new BadRequest('This Lead has already been converted.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'nexa_lead_conversion';
        $ownsTransaction ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT {$savepoint}");

        try {
            $result = $nativeConvert();
            $converted = $this->convertedTargets($pdo, $context, $leadId);
            $correlationId = $this->uuid();

            $this->propagateProfile($snapshot, $converted);
            $this->copyCustomValues($pdo, $context, $leadId, $converted);
            $this->copyTargetLists($pdo, $context, $leadId, $converted['contact_id']);
            $this->moveCampaignHistory($pdo, $context, $leadId, $converted);
            $this->copyGovernedRelationships($pdo, $context, $leadId, $converted);
            $this->recordConversion($pdo, $context, $leadId, $converted, $snapshot, $correlationId);

            $ownsTransaction ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function leadSnapshot(PDO $pdo, TenantContext $context, string $leadId): array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM lead WHERE id = ? AND tenant_id = ? AND service_id = ? AND deleted = 0 FOR UPDATE'
        );
        $statement->execute([$leadId, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new NotFound('Lead was not found in this workspace.');
        }
        return $row;
    }

    /** @return array{contact_id: ?string, account_id: ?string, opportunity_id: ?string} */
    private function convertedTargets(PDO $pdo, TenantContext $context, string $leadId): array
    {
        $statement = $pdo->prepare(
            'SELECT created_contact_id, created_account_id, created_opportunity_id FROM lead ' .
            'WHERE id = ? AND tenant_id = ? AND service_id = ? AND status = ?'
        );
        $statement->execute([$leadId, $context->tenantId, $context->serviceId, 'Converted']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new BadRequest('Lead conversion did not complete.');
        }
        return [
            'contact_id' => $this->optionalId($row['created_contact_id'] ?? null),
            'account_id' => $this->optionalId($row['created_account_id'] ?? null),
            'opportunity_id' => $this->optionalId($row['created_opportunity_id'] ?? null),
        ];
    }

    /** @param array<string, mixed> $snapshot @param array<string, ?string> $targets */
    private function propagateProfile(array $snapshot, array $targets): void
    {
        $leadStatus = match ((string) ($snapshot['status'] ?? '')) {
            'New' => 'New',
            'Assigned' => 'Open',
            'In Process' => 'InProgress',
            'Recycled' => 'BadTiming',
            'Dead' => 'Unqualified',
            default => 'Open',
        };
        $shared = [
            'leadScore' => (int) ($snapshot['lead_score'] ?? 0),
            'lifecycleStage' => $snapshot['lifecycle_stage'] ?: 'Lead',
            'leadStatus' => $leadStatus,
        ];

        if ($targets['contact_id']) {
            $contact = $this->entityManager->getRDBRepository('Contact')->getById($targets['contact_id']);
            if (!$contact) {
                throw new NotFound('Converted Contact was not found.');
            }
            foreach ($shared + [
                'source' => $snapshot['source'] ?? null,
                'marketingStatus' => $snapshot['marketing_status'] ?? 'Non-Marketing',
                'legalBasis' => $snapshot['legal_basis'] ?? null,
                'lastWebsiteVisitAt' => $snapshot['last_website_visit_at'] ?? null,
                'firstActivityAt' => $snapshot['first_activity_at'] ?? null,
                'lastActivityAt' => $snapshot['last_activity_at'] ?? null,
                'lastEmailInteractionAt' => $snapshot['last_email_interaction_at'] ?? null,
                'nextActivityAt' => $snapshot['next_activity_at'] ?? null,
            ] as $field => $value) {
                $current = $contact->get($field);
                $isInitialValue = $current === null || $current === '' || $current === 0 ||
                    ($field === 'marketingStatus' && $current === 'Non-Marketing');
                if ($isInitialValue && $value !== null && $value !== '') {
                    $contact->set($field, $value);
                }
            }
            $this->entityManager->saveEntity($contact);
        }

        if ($targets['account_id']) {
            $account = $this->entityManager->getRDBRepository('Account')->getById($targets['account_id']);
            if (!$account) {
                throw new NotFound('Converted Account was not found.');
            }
            foreach ($shared as $field => $value) {
                if (($account->get($field) === null || $account->get($field) === '' || $account->get($field) === 0) && $value !== null && $value !== '') {
                    $account->set($field, $value);
                }
            }
            $this->entityManager->saveEntity($account);
        }
    }

    /** @param array<string, ?string> $targets */
    private function copyCustomValues(PDO $pdo, TenantContext $context, string $leadId, array $targets): void
    {
        foreach (['Contact' => $targets['contact_id'], 'Account' => $targets['account_id']] as $entityType => $targetId) {
            if (!$targetId) {
                continue;
            }
            $statement = $pdo->prepare(
                'SELECT target.id AS target_definition_id, value.value_text, value.value_number, value.value_date, ' .
                'value.value_datetime, value.value_boolean, value.value_json FROM nexa_custom_field_value value ' .
                'INNER JOIN nexa_custom_field_definition source ON source.id = value.field_definition_id ' .
                'AND source.tenant_id = value.tenant_id AND source.service_id = value.service_id ' .
                'INNER JOIN nexa_custom_field_definition target ON target.tenant_id = source.tenant_id ' .
                'AND target.service_id = source.service_id AND target.field_key = source.field_key ' .
                'AND target.entity_type = ? AND target.is_active = 1 ' .
                "WHERE value.tenant_id = ? AND value.service_id = ? AND value.entity_type = 'Lead' " .
                'AND value.entity_id = ? AND source.is_active = 1'
            );
            $statement->execute([$entityType, $context->tenantId, $context->serviceId, $leadId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $insert = $pdo->prepare(
                    'INSERT IGNORE INTO nexa_custom_field_value ' .
                    '(id, tenant_id, service_id, field_definition_id, entity_type, entity_id, value_text, value_number, ' .
                    'value_date, value_datetime, value_boolean, value_json, created_by_id, updated_by_id) ' .
                    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $this->uuid(), $context->tenantId, $context->serviceId, $row['target_definition_id'],
                    $entityType, $targetId, $row['value_text'], $row['value_number'], $row['value_date'],
                    $row['value_datetime'], $row['value_boolean'], $row['value_json'], $this->actorId(), $this->actorId(),
                ]);
            }
        }
    }

    private function copyTargetLists(PDO $pdo, TenantContext $context, string $leadId, ?string $contactId): void
    {
        if (!$contactId) {
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO contact_target_list (contact_id, target_list_id, opted_out, deleted, tenant_id, service_id) ' .
            'SELECT ?, target_list_id, opted_out, 0, tenant_id, service_id FROM lead_target_list ' .
            'WHERE lead_id = ? AND tenant_id = ? AND service_id = ? AND deleted = 0 ' .
            'ON DUPLICATE KEY UPDATE opted_out = VALUES(opted_out), deleted = 0'
        );
        $statement->execute([$contactId, $leadId, $context->tenantId, $context->serviceId]);
    }

    /** @param array<string, ?string> $targets */
    private function moveCampaignHistory(PDO $pdo, TenantContext $context, string $leadId, array $targets): void
    {
        $targetId = $targets['contact_id'] ?? $targets['account_id'];
        $targetType = $targets['contact_id'] ? 'Contact' : ($targets['account_id'] ? 'Account' : null);
        if (!$targetId || !$targetType) {
            return;
        }
        $statement = $pdo->prepare(
            'UPDATE campaign_log_record SET parent_id = ?, parent_type = ? WHERE parent_id = ? AND parent_type = ? ' .
            'AND tenant_id = ? AND service_id = ?'
        );
        $statement->execute([$targetId, $targetType, $leadId, 'Lead', $context->tenantId, $context->serviceId]);
    }

    /** @param array<string, ?string> $targets */
    private function copyGovernedRelationships(PDO $pdo, TenantContext $context, string $leadId, array $targets): void
    {
        $targetId = $targets['contact_id'] ?? $targets['account_id'];
        $targetType = $targets['contact_id'] ? 'Contact' : ($targets['account_id'] ? 'Account' : null);
        if (!$targetId || !$targetType) {
            return;
        }

        foreach ([
            ['nexa_relationship_edge', 'relationship_type_id', false],
            ['nexa_custom_relationship_link', 'relationship_definition_id', true],
        ] as [$table, $definitionColumn, $serviceScoped]) {
            $serviceClause = $serviceScoped ? ' AND service_id = ?' : '';
            $params = [$context->tenantId];
            if ($serviceScoped) {
                $params[] = $context->serviceId;
            }
            array_push($params, 'Lead', $leadId, 'Lead', $leadId);
            $statement = $pdo->prepare(
                "SELECT * FROM {$table} WHERE tenant_id = ?{$serviceClause} AND deleted_at IS NULL AND " .
                '((source_entity_type = ? AND source_entity_id = ?) OR (target_entity_type = ? AND target_entity_id = ?))'
            );
            $statement->execute($params);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sourceType = $row['source_entity_type'] === 'Lead' && $row['source_entity_id'] === $leadId ? $targetType : $row['source_entity_type'];
                $sourceId = $row['source_entity_type'] === 'Lead' && $row['source_entity_id'] === $leadId ? $targetId : $row['source_entity_id'];
                $relatedType = $row['target_entity_type'] === 'Lead' && $row['target_entity_id'] === $leadId ? $targetType : $row['target_entity_type'];
                $relatedId = $row['target_entity_type'] === 'Lead' && $row['target_entity_id'] === $leadId ? $targetId : $row['target_entity_id'];
                $columns = $serviceScoped
                    ? '(id,tenant_id,service_id,' . $definitionColumn . ',source_entity_type,source_entity_id,target_entity_type,target_entity_id,created_by_id)'
                    : '(id,tenant_id,' . $definitionColumn . ',source_entity_type,source_entity_id,target_entity_type,target_entity_id,created_by_id)';
                $values = $serviceScoped ? '(?,?,?,?,?,?,?,?,?)' : '(?,?,?,?,?,?,?,?)';
                $insert = $pdo->prepare("INSERT IGNORE INTO {$table} {$columns} VALUES {$values}");
                $insertParams = [$this->uuid(), $context->tenantId];
                if ($serviceScoped) {
                    $insertParams[] = $context->serviceId;
                }
                array_push($insertParams, $row[$definitionColumn], $sourceType, $sourceId, $relatedType, $relatedId, $this->actorId());
                $insert->execute($insertParams);
            }
        }
    }

    /** @param array<string, ?string> $targets @param array<string, mixed> $snapshot */
    private function recordConversion(PDO $pdo, TenantContext $context, string $leadId, array $targets, array $snapshot, string $correlationId): void
    {
        $snapshot = array_intersect_key($snapshot, array_flip([
            'first_name', 'last_name', 'account_name', 'status', 'source', 'rating', 'lead_score',
            'lifecycle_stage', 'marketing_status', 'legal_basis', 'assigned_user_id', 'campaign_id',
        ]));
        $metadata = ['targets' => $targets, 'source' => $snapshot['source'] ?? null, 'snapshot' => $snapshot];
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $statement = $pdo->prepare(
            'INSERT INTO nexa_lead_conversion (id,tenant_id,service_id,lead_id,contact_id,account_id,opportunity_id,converted_by_id,correlation_id,snapshot_json) ' .
            'VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $leadId, $targets['contact_id'],
            $targets['account_id'], $targets['opportunity_id'], $this->actorId(), $correlationId, $json,
        ]);

        $update = $pdo->prepare(
            'UPDATE lead SET converted_by_id = ? WHERE id = ? AND tenant_id = ? AND service_id = ?'
        );
        $update->execute([$this->actorId(), $leadId, $context->tenantId, $context->serviceId]);

        $audit = $pdo->prepare(
            "INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json) " .
            "VALUES (?,?,?,'user',?,'lead.converted','Lead',?,?,'lead-conversion',?)"
        );
        $audit->execute([$this->uuid(), $context->tenantId, $context->serviceId, $this->actorId(), $leadId, $correlationId, $metadataJson]);

        $outbox = $pdo->prepare(
            "INSERT INTO nexa_outbox_event (id,tenant_id,service_id,event_type,aggregate_type,aggregate_id,payload_json,correlation_id) " .
            "VALUES (?,?,?,'lead.converted','Lead',?,?,?)"
        );
        $outbox->execute([$this->uuid(), $context->tenantId, $context->serviceId, $leadId, $metadataJson, $correlationId]);

        if ($targets['contact_id'] || $targets['account_id']) {
            $timeline = $pdo->prepare(
                "INSERT INTO nexa_timeline_event (id,tenant_id,contact_id,account_id,event_type,source_entity_type,source_entity_id,source_occurred_at,actor_type,actor_id,correlation_id,summary,metadata_json) " .
                "VALUES (?,?,?,?, 'lead.converted','Lead',?,CURRENT_TIMESTAMP(6),'user',?,?, 'Lead converted',?)"
            );
            $timeline->execute([
                $this->uuid(), $context->tenantId, $targets['contact_id'], $targets['account_id'],
                $leadId, $this->actorId(), $correlationId, $metadataJson,
            ]);
        }
    }

    private function optionalId(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function actorId(): ?string
    {
        $id = trim((string) $this->user->getId());
        return $id !== '' ? $id : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
