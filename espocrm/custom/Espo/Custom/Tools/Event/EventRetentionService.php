<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Security\SecurityAuditService;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Tenant-governed, bounded retention for the canonical behavior-event store. */
final class EventRetentionService
{
    private const DEFAULT_IDENTIFIED_DAYS = 730;
    private const DEFAULT_ANONYMOUS_DAYS = 90;
    private const DEFAULT_REPLAY_DAYS = 90;
    private const PURGE_BATCH_SIZE = 5000;

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
        private Acl $acl,
        private SecurityAuditService $securityAuditService,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $policy = $this->policy($context);

        return [
            'policy' => $this->policyPayload($policy),
            'storage' => $this->storage($context, $policy),
            'limits' => [
                'identifiedDays' => ['min' => 90, 'max' => 2555],
                'anonymousDays' => ['min' => 30, 'max' => 730],
                'replayDays' => ['min' => 30, 'max' => 365],
                'purgeBatchSize' => self::PURGE_BATCH_SIZE,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function update(stdClass $input): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $identifiedDays = (int) ($input->identifiedDays ?? 0);
        $anonymousDays = (int) ($input->anonymousDays ?? 0);
        $replayDays = (int) ($input->replayDays ?? 0);
        $legalHold = ($input->legalHold ?? false) === true;
        $legalHoldReason = trim((string) ($input->legalHoldReason ?? ''));

        if ($identifiedDays < 90 || $identifiedDays > 2555) {
            throw new BadRequest('Keep identified behavior for between 90 days and 7 years.');
        }
        if ($anonymousDays < 30 || $anonymousDays > 730) {
            throw new BadRequest('Keep anonymous behavior for between 30 days and 2 years.');
        }
        if ($anonymousDays > $identifiedDays) {
            throw new BadRequest('Anonymous retention cannot exceed identified-customer retention.');
        }
        if ($replayDays < 30 || $replayDays > 365) {
            throw new BadRequest('Keep completed replay requests for between 30 days and 1 year.');
        }
        if ($legalHold && mb_strlen($legalHoldReason) < 10) {
            throw new BadRequest('Describe the legal-hold reason using at least 10 characters.');
        }
        if (mb_strlen($legalHoldReason) > 500) {
            throw new BadRequest('The legal-hold reason is too long.');
        }
        if (!$legalHold) {
            $legalHoldReason = '';
        }

        $previous = $this->policy($context);
        $id = $previous['id'] ?? $this->uuid();
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_event_retention_policy ' .
            '(id,tenant_id,service_id,identified_retention_days,anonymous_retention_days,replay_retention_days,' .
            'legal_hold,legal_hold_reason,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?) ' .
            'ON DUPLICATE KEY UPDATE identified_retention_days=VALUES(identified_retention_days),' .
            'anonymous_retention_days=VALUES(anonymous_retention_days),replay_retention_days=VALUES(replay_retention_days),' .
            'legal_hold=VALUES(legal_hold),legal_hold_reason=VALUES(legal_hold_reason),updated_by_id=VALUES(updated_by_id)'
        );
        $statement->execute([
            $id,
            $context->tenantId,
            $context->serviceId,
            $identifiedDays,
            $anonymousDays,
            $replayDays,
            $legalHold ? 1 : 0,
            $legalHoldReason !== '' ? $legalHoldReason : null,
            $this->user->getId() ?: null,
        ]);

        $this->securityAuditService->append(
            $context,
            'event.retention.policy.updated',
            'EventRetentionPolicy',
            $id,
            metadata: [
                'previous' => $this->policyPayload($previous),
                'current' => [
                    'identifiedDays' => $identifiedDays,
                    'anonymousDays' => $anonymousDays,
                    'replayDays' => $replayDays,
                    'legalHold' => $legalHold,
                    'legalHoldReason' => $legalHoldReason,
                ],
            ],
        );

        return $this->workspace();
    }

    /** @return array<string, mixed> */
    public function purgeNow(): array
    {
        $this->requireAdmin();
        return $this->purge('manual');
    }

    /** @return array<string, mixed> */
    public function purgeScheduled(): array
    {
        return $this->purge('scheduled');
    }

    /** @return array<string, mixed> */
    private function purge(string $source): array
    {
        $context = $this->tenantContextStore->require();
        $policy = $this->policy($context);
        if ((bool) $policy['legal_hold']) {
            return [
                'held' => true,
                'eventsDeleted' => 0,
                'replaysDeleted' => 0,
                'visitorsDeleted' => 0,
                'batchLimit' => self::PURGE_BATCH_SIZE,
            ];
        }

        $identifiedCutoff = $this->cutoff((int) $policy['identified_retention_days']);
        $anonymousCutoff = $this->cutoff((int) $policy['anonymous_retention_days']);
        $replayCutoff = $this->cutoff((int) $policy['replay_retention_days']);
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $eventIds = $this->eligibleEventIds($context, $identifiedCutoff, $anonymousCutoff);
            $eventsDeleted = count($eventIds);
            if ($eventIds !== []) {
                $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
                $scope = [$context->tenantId, $context->serviceId, ...$eventIds];
                $pdo->prepare(
                    "DELETE FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? " .
                    "AND source_entity_type='BehaviorEvent' AND source_entity_id IN ({$placeholders})"
                )->execute($scope);
                $pdo->prepare(
                    "DELETE FROM nexa_outbox_event WHERE tenant_id=? AND service_id=? AND published_at IS NOT NULL " .
                    "AND aggregate_type='BehaviorEvent' AND aggregate_id IN ({$placeholders})"
                )->execute($scope);
                $pdo->prepare(
                    "DELETE FROM nexa_behavior_event WHERE tenant_id=? AND service_id=? AND id IN ({$placeholders})"
                )->execute($scope);
            }

            $replayRows = $this->eligibleReplayRows($context, $replayCutoff);
            $replaysDeleted = count($replayRows);
            if ($replayRows !== []) {
                $replayIds = array_column($replayRows, 'id');
                $correlationIds = array_column($replayRows, 'correlation_id');
                $replayPlaceholders = implode(',', array_fill(0, count($replayIds), '?'));
                $pdo->prepare(
                    "DELETE FROM nexa_outbox_event WHERE tenant_id=? AND service_id=? AND published_at IS NOT NULL " .
                    "AND event_type='behavior.event.replay.requested' AND correlation_id IN ({$replayPlaceholders})"
                )->execute([$context->tenantId, $context->serviceId, ...$correlationIds]);
                $pdo->prepare(
                    "DELETE FROM nexa_behavior_event_replay WHERE tenant_id=? AND service_id=? " .
                    "AND id IN ({$replayPlaceholders})"
                )->execute([$context->tenantId, $context->serviceId, ...$replayIds]);
            }

            $visitorIds = $this->orphanVisitorIds($context, $identifiedCutoff, $anonymousCutoff);
            $visitorsDeleted = count($visitorIds);
            if ($visitorIds !== []) {
                $placeholders = implode(',', array_fill(0, count($visitorIds), '?'));
                $pdo->prepare(
                    "DELETE FROM nexa_visitor_identity WHERE tenant_id=? AND service_id=? AND id IN ({$placeholders})"
                )->execute([$context->tenantId, $context->serviceId, ...$visitorIds]);
            }

            $result = [
                'held' => false,
                'eventsDeleted' => $eventsDeleted,
                'replaysDeleted' => $replaysDeleted,
                'visitorsDeleted' => $visitorsDeleted,
                'batchLimit' => self::PURGE_BATCH_SIZE,
            ];
            if ($source === 'manual' || $eventsDeleted + $replaysDeleted + $visitorsDeleted > 0) {
                $this->securityAuditService->append(
                    $context,
                    'event.retention.purge.completed',
                    'BehaviorEvent',
                    null,
                    metadata: $result + [
                        'source' => $source,
                        'identifiedCutoff' => $identifiedCutoff,
                        'anonymousCutoff' => $anonymousCutoff,
                        'replayCutoff' => $replayCutoff,
                    ],
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    private function eligibleEventIds(
        TenantContext $context,
        string $identifiedCutoff,
        string $anonymousCutoff,
    ): array {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT e.id FROM nexa_behavior_event e WHERE e.tenant_id=? AND e.service_id=? ' .
            'AND ((e.contact_id IS NULL AND e.occurred_at<?) OR (e.contact_id IS NOT NULL AND e.occurred_at<?)) ' .
            'AND NOT EXISTS (SELECT 1 FROM nexa_behavior_event_replay r WHERE r.tenant_id=e.tenant_id ' .
            "AND r.service_id=e.service_id AND r.behavior_event_id=e.id AND r.status IN ('queued','processing')) " .
            'AND NOT EXISTS (SELECT 1 FROM nexa_outbox_event o WHERE o.tenant_id=e.tenant_id ' .
            "AND o.service_id=e.service_id AND o.aggregate_type='BehaviorEvent' AND o.aggregate_id=e.id " .
            'AND o.published_at IS NULL) ORDER BY e.occurred_at,e.id LIMIT ' . self::PURGE_BATCH_SIZE
        );
        $statement->execute([
            $context->tenantId,
            $context->serviceId,
            $anonymousCutoff,
            $identifiedCutoff,
        ]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function orphanVisitorIds(
        TenantContext $context,
        string $identifiedCutoff,
        string $anonymousCutoff,
    ): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT v.id FROM nexa_visitor_identity v WHERE v.tenant_id=? AND v.service_id=? ' .
            'AND ((v.contact_id IS NULL AND v.last_seen_at<?) OR (v.contact_id IS NOT NULL AND v.last_seen_at<?)) ' .
            'AND NOT EXISTS (SELECT 1 FROM nexa_behavior_event e WHERE e.tenant_id=v.tenant_id ' .
            'AND e.service_id=v.service_id AND e.visitor_identity_id=v.id) ' .
            'ORDER BY v.last_seen_at,v.id LIMIT ' . self::PURGE_BATCH_SIZE
        );
        $statement->execute([
            $context->tenantId,
            $context->serviceId,
            $anonymousCutoff,
            $identifiedCutoff,
        ]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int, array{id: string, correlation_id: string}> */
    private function eligibleReplayRows(TenantContext $context, string $replayCutoff): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT r.id,r.correlation_id FROM nexa_behavior_event_replay r ' .
            'WHERE r.tenant_id=? AND r.service_id=? ' .
            "AND r.status IN ('completed','failed','cancelled') AND r.requested_at<? " .
            'AND NOT EXISTS (SELECT 1 FROM nexa_outbox_event o WHERE o.tenant_id=r.tenant_id ' .
            "AND o.service_id=r.service_id AND o.event_type='behavior.event.replay.requested' " .
            'AND o.correlation_id=r.correlation_id AND o.published_at IS NULL) ' .
            'ORDER BY r.requested_at,r.id LIMIT ' . self::PURGE_BATCH_SIZE
        );
        $statement->execute([$context->tenantId, $context->serviceId, $replayCutoff]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function storage(TenantContext $context, array $policy): array
    {
        $identifiedCutoff = $this->cutoff((int) $policy['identified_retention_days']);
        $anonymousCutoff = $this->cutoff((int) $policy['anonymous_retention_days']);
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT COUNT(*) AS total_events, SUM(contact_id IS NOT NULL) AS identified_events, ' .
            'SUM(contact_id IS NULL) AS anonymous_events, MIN(occurred_at) AS oldest_event_at ' .
            'FROM nexa_behavior_event WHERE tenant_id=? AND service_id=?'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $eligible = $this->entityManager->getPDO()->prepare(
            'SELECT COUNT(*) FROM nexa_behavior_event e WHERE e.tenant_id=? AND e.service_id=? ' .
            'AND ((e.contact_id IS NULL AND e.occurred_at<?) OR (e.contact_id IS NOT NULL AND e.occurred_at<?)) ' .
            'AND NOT EXISTS (SELECT 1 FROM nexa_behavior_event_replay r WHERE r.tenant_id=e.tenant_id ' .
            "AND r.service_id=e.service_id AND r.behavior_event_id=e.id AND r.status IN ('queued','processing')) " .
            'AND NOT EXISTS (SELECT 1 FROM nexa_outbox_event o WHERE o.tenant_id=e.tenant_id ' .
            "AND o.service_id=e.service_id AND o.aggregate_type='BehaviorEvent' AND o.aggregate_id=e.id " .
            'AND o.published_at IS NULL)'
        );
        $eligible->execute([
            $context->tenantId,
            $context->serviceId,
            $anonymousCutoff,
            $identifiedCutoff,
        ]);
        $replays = $this->entityManager->getPDO()->prepare(
            'SELECT COUNT(*) FROM nexa_behavior_event_replay WHERE tenant_id=? AND service_id=?'
        );
        $replays->execute([$context->tenantId, $context->serviceId]);

        return [
            'totalEvents' => (int) ($row['total_events'] ?? 0),
            'identifiedEvents' => (int) ($row['identified_events'] ?? 0),
            'anonymousEvents' => (int) ($row['anonymous_events'] ?? 0),
            'eligibleEvents' => (int) $eligible->fetchColumn(),
            'oldestEventAt' => $row['oldest_event_at'] ?? null,
            'replayRequests' => (int) $replays->fetchColumn(),
        ];
    }

    /** @return array<string, mixed> */
    private function policy(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT * FROM nexa_event_retention_policy WHERE tenant_id=? AND service_id=? LIMIT 1'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [
            'id' => null,
            'identified_retention_days' => self::DEFAULT_IDENTIFIED_DAYS,
            'anonymous_retention_days' => self::DEFAULT_ANONYMOUS_DAYS,
            'replay_retention_days' => self::DEFAULT_REPLAY_DAYS,
            'legal_hold' => 0,
            'legal_hold_reason' => null,
            'updated_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function policyPayload(array $policy): array
    {
        return [
            'id' => $policy['id'] ?? null,
            'identifiedDays' => (int) $policy['identified_retention_days'],
            'anonymousDays' => (int) $policy['anonymous_retention_days'],
            'replayDays' => (int) $policy['replay_retention_days'],
            'legalHold' => (bool) $policy['legal_hold'],
            'legalHoldReason' => (string) ($policy['legal_hold_reason'] ?? ''),
            'updatedAt' => $policy['updated_at'] ?? null,
        ];
    }

    private function cutoff(int $days): string
    {
        return (new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s.u');
    }

    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin() || !$this->acl->checkScope('Contact')) {
            throw new Forbidden('Tenant administrator access is required.');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
