<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use PDO;
use stdClass;

/** Replay-safe behavioral event ingestion projected to the existing timeline and outbox. */
final class BehaviorEventService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private EventContract $eventContract,
    ) {}

    /** @return array<string, mixed> */
    public function ingest(stdClass $input): array
    {
        $context = $this->tenantContextStore->require();
        $event = $this->eventContract->normalize($input);
        $pdo = $this->entityManager->getPDO();
        $find = $pdo->prepare(
            'SELECT id FROM nexa_behavior_event ' .
            'WHERE tenant_id = ? AND service_id = ? AND source = ? AND idempotency_key = ?'
        );
        $find->execute([$context->tenantId, $context->serviceId, $event['source'], $event['idempotencyKey']]);
        if ($id = $find->fetchColumn()) {
            return ['id' => $id, 'duplicate' => true];
        }

        $contactId = $this->owned('contact', $event['contactId']);
        $accountId = $this->owned('account', $event['accountId']);
        $correlationId = $event['correlationId'] ?? $this->uuid();
        $id = $this->uuid();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $visitorId = $this->visitor(
                $event['visitorKey'],
                $contactId,
                $event['occurredAt'],
                $event['identityMethod'],
                $event['identityEvidenceHash'],
                $id,
            );

            // Once a visitor has been verified, later events inherit that identity.
            if ($contactId === null && $visitorId !== null) {
                $contactId = $this->visitorContact($visitorId);
            }
            if ($accountId === null && $contactId !== null) {
                $accountId = $this->contactAccount($contactId);
            }

            $properties = $this->json($event['properties']);
            $consent = $this->json($event['consent']);
            $metadata = $this->json([
                'eventCategory' => $event['eventCategory'],
                'consentCategory' => $event['consentCategory'],
                'pageUrl' => $event['pageUrl'],
                'referrerUrl' => $event['referrerUrl'],
                'properties' => $event['properties'],
            ]);

            $pdo->prepare(
                'INSERT INTO nexa_behavior_event ' .
                '(id, tenant_id, service_id, event_type, event_version, event_category, consent_category, ' .
                'source, idempotency_key, correlation_id, visitor_identity_id, contact_id, account_id, ' .
                'session_key_hash, page_url, referrer_url, properties_json, consent_json, occurred_at) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id, $context->tenantId, $context->serviceId, $event['eventType'], $event['eventVersion'],
                $event['eventCategory'], $event['consentCategory'], $event['source'], $event['idempotencyKey'],
                $correlationId, $visitorId, $contactId, $accountId, $this->hash($event['sessionKey']),
                $event['pageUrl'], $event['referrerUrl'], $properties, $consent, $event['occurredAt'],
            ]);

            if ($contactId !== null || $accountId !== null) {
                $this->projectTimeline(
                    $id,
                    $contactId,
                    $accountId,
                    $event['eventType'],
                    $event['occurredAt'],
                    $correlationId,
                    $event['summary'],
                    $metadata,
                );
            }

            if ($visitorId !== null && $contactId !== null) {
                $this->backfillVisitorEvents($visitorId, $contactId, $accountId);
                $this->updateLastWebsiteVisit($contactId, $visitorId);
            }

            $pdo->prepare(
                "INSERT INTO nexa_outbox_event " .
                "(id, tenant_id, service_id, event_type, aggregate_type, aggregate_id, payload_json, correlation_id) " .
                "VALUES (?, ?, ?, 'behavior.event.recorded', 'BehaviorEvent', ?, ?, ?)"
            )->execute([
                $this->uuid(),
                $context->tenantId,
                $context->serviceId,
                $id,
                json_encode([
                    'eventId' => $id,
                    'eventType' => $event['eventType'],
                    'contactId' => $contactId,
                    'accountId' => $accountId,
                ], JSON_THROW_ON_ERROR),
                $correlationId,
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                $find->execute([$context->tenantId, $context->serviceId, $event['source'], $event['idempotencyKey']]);
                if ($found = $find->fetchColumn()) {
                    return ['id' => $found, 'duplicate' => true];
                }
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'id' => $id,
            'duplicate' => false,
            'correlationId' => $correlationId,
            'identified' => $contactId !== null,
        ];
    }

    private function visitor(
        mixed $rawKey,
        ?string $contactId,
        string $occurredAt,
        ?string $method,
        ?string $evidenceHash,
        string $eventId,
    ): ?string {
        $hash = $this->hash($rawKey);
        if ($hash === null) {
            return null;
        }

        $legacyHash = hash('sha256', (string) $rawKey);
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $statement = $pdo->prepare(
            'SELECT id, contact_id, visitor_key_hash FROM nexa_visitor_identity ' .
            'WHERE tenant_id = ? AND service_id = ? AND visitor_key_hash IN (?, ?) ' .
            'ORDER BY visitor_key_hash = ? DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $hash, $legacyHash, $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($contactId !== null && $row['contact_id'] && $row['contact_id'] !== $contactId) {
                throw new BadRequest('This visitor is already linked to a different contact.');
            }
            $pdo->prepare(
                'UPDATE nexa_visitor_identity SET visitor_key_hash = ?, last_seen_at = ?, ' .
                'identified_at = IF(identified_at IS NULL AND ? IS NOT NULL, ?, identified_at), ' .
                'resolution_method = COALESCE(resolution_method, ?), ' .
                'resolution_evidence_hash = COALESCE(resolution_evidence_hash, ?), ' .
                'resolved_by_event_id = COALESCE(resolved_by_event_id, ?), ' .
                'contact_id = COALESCE(contact_id, ?) WHERE id = ?'
            )->execute([
                $hash, $occurredAt, $contactId, $occurredAt, $method, $evidenceHash,
                $contactId ? $eventId : null, $contactId, $row['id'],
            ]);
            return (string) $row['id'];
        }

        $id = $this->uuid();
        $pdo->prepare(
            'INSERT INTO nexa_visitor_identity ' .
            '(id, tenant_id, service_id, visitor_key_hash, contact_id, first_seen_at, last_seen_at, identified_at, ' .
            'resolution_method, resolution_evidence_hash, resolved_by_event_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $context->tenantId, $context->serviceId, $hash, $contactId, $occurredAt, $occurredAt,
            $contactId ? $occurredAt : null, $method, $evidenceHash, $contactId ? $eventId : null,
        ]);
        return $id;
    }

    private function visitorContact(string $visitorId): ?string
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT v.contact_id FROM nexa_visitor_identity v ' .
            'INNER JOIN contact c ON c.id = v.contact_id AND c.tenant_id = v.tenant_id ' .
            'AND c.service_id = v.service_id AND c.deleted = 0 ' .
            'WHERE v.id = ? AND v.tenant_id = ? AND v.service_id = ?'
        );
        $statement->execute([$visitorId, $context->tenantId, $context->serviceId]);
        return $statement->fetchColumn() ?: null;
    }

    private function contactAccount(string $contactId): ?string
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT a.id FROM contact c INNER JOIN account a ON a.id = c.account_id ' .
            'AND a.tenant_id = c.tenant_id AND a.service_id = c.service_id AND a.deleted = 0 ' .
            'WHERE c.id = ? AND c.tenant_id = ? AND c.service_id = ? AND c.deleted = 0'
        );
        $statement->execute([$contactId, $context->tenantId, $context->serviceId]);
        return $statement->fetchColumn() ?: null;
    }

    private function backfillVisitorEvents(string $visitorId, string $contactId, ?string $accountId): void
    {
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $pdo->prepare(
            'UPDATE nexa_behavior_event SET contact_id = ?, account_id = COALESCE(account_id, ?) ' .
            'WHERE tenant_id = ? AND service_id = ? AND visitor_identity_id = ? AND contact_id IS NULL'
        )->execute([$contactId, $accountId, $context->tenantId, $context->serviceId, $visitorId]);

        $pdo->prepare(
            "INSERT IGNORE INTO nexa_timeline_event " .
            "(id, tenant_id, service_id, contact_id, account_id, event_type, source_entity_type, source_entity_id, " .
            "source_occurred_at, actor_type, visibility, correlation_id, summary, metadata_json) " .
            "SELECT UUID(), b.tenant_id, b.service_id, b.contact_id, b.account_id, b.event_type, 'BehaviorEvent', b.id, " .
            "b.occurred_at, 'system', 'internal', b.correlation_id, b.event_type, " .
            "JSON_OBJECT('eventCategory', b.event_category, 'consentCategory', b.consent_category, " .
            "'pageUrl', b.page_url, 'referrerUrl', b.referrer_url, 'properties', b.properties_json) " .
            'FROM nexa_behavior_event b WHERE b.tenant_id = ? AND b.service_id = ? ' .
            'AND b.visitor_identity_id = ? AND b.contact_id = ?'
        )->execute([$context->tenantId, $context->serviceId, $visitorId, $contactId]);
    }

    private function projectTimeline(
        string $eventId,
        ?string $contactId,
        ?string $accountId,
        string $eventType,
        string $occurredAt,
        string $correlationId,
        string $summary,
        string $metadata,
    ): void {
        $context = $this->tenantContextStore->require();
        $this->entityManager->getPDO()->prepare(
            "INSERT INTO nexa_timeline_event " .
            "(id, tenant_id, service_id, contact_id, account_id, event_type, source_entity_type, source_entity_id, " .
            "source_occurred_at, actor_type, visibility, correlation_id, summary, metadata_json) " .
            "VALUES (?, ?, ?, ?, ?, ?, 'BehaviorEvent', ?, ?, 'system', 'internal', ?, ?, ?)"
        )->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $contactId, $accountId, $eventType,
            $eventId, $occurredAt, $correlationId, $summary, $metadata,
        ]);
    }

    private function updateLastWebsiteVisit(string $contactId, string $visitorId): void
    {
        $context = $this->tenantContextStore->require();
        $this->entityManager->getPDO()->prepare(
            "UPDATE contact SET last_website_visit_at = COALESCE(GREATEST(last_website_visit_at, (" .
            "SELECT MAX(b.occurred_at) FROM nexa_behavior_event b " .
            "WHERE b.tenant_id = ? AND b.service_id = ? AND b.visitor_identity_id = ? " .
            "AND b.event_category = 'website'" .
            ")), (" .
            "SELECT MAX(b.occurred_at) FROM nexa_behavior_event b " .
            "WHERE b.tenant_id = ? AND b.service_id = ? AND b.visitor_identity_id = ? " .
            "AND b.event_category = 'website'" .
            "), last_website_visit_at) WHERE id = ? AND tenant_id = ? AND service_id = ? AND deleted = 0"
        )->execute([
            $context->tenantId, $context->serviceId, $visitorId,
            $context->tenantId, $context->serviceId, $visitorId,
            $contactId,
            $context->tenantId, $context->serviceId,
        ]);
    }

    private function owned(string $table, mixed $id): ?string
    {
        if (!$id) {
            return null;
        }
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT id FROM {$table} WHERE id = ? AND tenant_id = ? AND service_id = ? AND deleted = 0"
        );
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        if (!$statement->fetchColumn()) {
            throw new BadRequest('The related CRM record is unavailable.');
        }
        return (string) $id;
    }

    private function hash(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        $context = $this->tenantContextStore->require();
        return hash('sha256', $context->tenantId . "\0" . $context->serviceId . "\0" . $value);
    }

    private function json(mixed $value): string
    {
        return json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
