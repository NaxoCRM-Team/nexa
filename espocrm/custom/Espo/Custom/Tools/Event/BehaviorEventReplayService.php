<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Security\SecurityAuditService;
use Espo\Entities\User;
use PDO;
use PDOException;
use stdClass;

/** Creates auditable replay requests without duplicating the canonical event or timeline row. */
final class BehaviorEventReplayService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private SecurityAuditService $securityAuditService,
        private User $user,
    ) {}

    /** @return array{id: string, eventId: string, status: string, duplicate: bool, correlationId: string} */
    public function request(string $eventId, stdClass $input): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can replay customer events.');
        }

        $replayKey = $this->requiredText($input->replayKey ?? null, 191, 'Provide a replay key.');
        if (mb_strlen($replayKey) < 8) {
            throw new BadRequest('The replay key must contain at least eight characters.');
        }
        $reason = $this->requiredText($input->reason ?? null, 500, 'Explain why this event must be replayed.');
        if (mb_strlen($reason) < 10) {
            throw new BadRequest('The replay reason must contain at least ten characters.');
        }

        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $eventQuery = $pdo->prepare(
                'SELECT id,event_type FROM nexa_behavior_event ' .
                'WHERE id=? AND tenant_id=? AND service_id=? LIMIT 1 FOR UPDATE'
            );
            $eventQuery->execute([$eventId, $context->tenantId, $context->serviceId]);
            $event = $eventQuery->fetch(PDO::FETCH_ASSOC);
            if (!$event) {
                throw new NotFound('The customer event is unavailable.');
            }

            $existing = $this->findExisting($pdo, $eventId, $replayKey);
            if ($existing !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $existing + ['duplicate' => true];
            }

            $id = $this->uuid();
            $correlationId = $this->uuid();
            $pdo->prepare(
                'INSERT INTO nexa_behavior_event_replay ' .
                '(id,tenant_id,service_id,behavior_event_id,replay_key,reason,requested_by_id,correlation_id) ' .
                'VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                $context->tenantId,
                $context->serviceId,
                $eventId,
                $replayKey,
                $reason,
                $this->user->getId() ?: null,
                $correlationId,
            ]);

            $payload = json_encode([
                'replayId' => $id,
                'eventId' => $eventId,
                'eventType' => $event['event_type'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $pdo->prepare(
                "INSERT INTO nexa_outbox_event " .
                "(id,tenant_id,service_id,event_type,aggregate_type,aggregate_id,payload_json,correlation_id) " .
                "VALUES (?,?,?,'behavior.event.replay.requested','BehaviorEvent',?,?,?)"
            )->execute([
                $this->uuid(),
                $context->tenantId,
                $context->serviceId,
                $eventId,
                $payload,
                $correlationId,
            ]);

            $this->securityAuditService->append(
                $context,
                'behavior.event.replay.requested',
                'behavior_event',
                $eventId,
                'success',
                ['replayId' => $id, 'reason' => $reason],
                correlationId: $correlationId,
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'id' => $id,
                'eventId' => $eventId,
                'status' => 'queued',
                'duplicate' => false,
                'correlationId' => $correlationId,
            ];
        } catch (PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                $existing = $this->findExisting($pdo, $eventId, $replayKey);
                if ($existing !== null) {
                    return $existing + ['duplicate' => true];
                }
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{id: string, eventId: string, status: string, correlationId: string}|null */
    private function findExisting(PDO $pdo, string $eventId, string $replayKey): ?array
    {
        $context = $this->tenantContextStore->require();
        $statement = $pdo->prepare(
            'SELECT id,behavior_event_id,status,correlation_id FROM nexa_behavior_event_replay ' .
            'WHERE tenant_id=? AND service_id=? AND behavior_event_id=? AND replay_key=? LIMIT 1'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $eventId, $replayKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? [
            'id' => (string) $row['id'],
            'eventId' => (string) $row['behavior_event_id'],
            'status' => (string) $row['status'],
            'correlationId' => (string) $row['correlation_id'],
        ] : null;
    }

    private function requiredText(mixed $value, int $maxLength, string $message): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            throw new BadRequest($message);
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

