<?php

namespace Espo\Custom\Tools\Call;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\Notification;
use Espo\Entities\User;
use PDO;

/**
 * Credit-request/approval workflow for when a tenant's shared calling-minutes
 * pool runs dry. Kept as a raw SaaS-admin table (nexa_call_credit_request),
 * matching nexa_call_session's proven pattern - this app has never registered
 * a brand-new EspoCRM entity type, so the review UI is a hand-rolled admin
 * panel rather than a native entity list/detail view.
 *
 * Every raw-PDO write here is a single atomic statement (a guarded UPDATE or
 * an upsert) with no explicit transaction wrapper - Notification creation
 * (an ORM write) always happens standalone, after any raw PDO work, never
 * nested inside a raw transaction. See CallMinutesLedger for why that
 * discipline matters.
 */
final class CreditRequestService
{
    private const SERVICE_KEY = 'voice.calling_minutes';

    private const MAX_REQUESTED_MINUTES = 500;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private TenantContextStore $tenantContextStore,
        private CallMinutesLedger $ledger,
    ) {}

    /** @return array{id: string} */
    public function submitRequest(string $reason, int $requestedMinutes): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new BadRequest('Explain why you need more calling minutes.');
        }

        if ($requestedMinutes < 1 || $requestedMinutes > self::MAX_REQUESTED_MINUTES) {
            throw new BadRequest('Enter a number of minutes between 1 and ' . self::MAX_REQUESTED_MINUTES . '.');
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);
        $periodKey = gmdate('Y-m');

        $adminIds = $this->tenantAdminUserIds($tenant->tenantId);

        if ($adminIds === []) {
            throw new Forbidden('No tenant administrator is available to review this request.');
        }

        $id = $this->uuid();

        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_call_credit_request ' .
            '(id, tenant_id, service_id, requested_by_user_id, requested_minutes, reason, status, period_key) ' .
            'VALUES (:id, :tenantId, :serviceId, :userId, :requestedMinutes, :reason, \'pending\', :periodKey)'
        );
        $statement->execute([
            'id' => $id,
            'tenantId' => $tenant->tenantId,
            'serviceId' => $serviceId,
            'userId' => $this->user->getId(),
            'requestedMinutes' => $requestedMinutes,
            'reason' => $reason,
            'periodKey' => $periodKey,
        ]);

        $requesterName = $this->user->get('name') ?: 'A user';

        foreach ($adminIds as $adminId) {
            $this->entityManager->createEntity(Notification::ENTITY_TYPE, [
                'type' => Notification::TYPE_SYSTEM,
                'userId' => $adminId,
                'data' => [
                    'message' => $requesterName . ' requested ' . $requestedMinutes .
                        ' more calling minutes: "' . $reason . '". Review it in your dashboard.',
                ],
            ]);
        }

        return ['id' => $id];
    }

    /** @return list<array<string, mixed>> */
    public function listRequests(string $status): array
    {
        $this->requireTenantAdmin();

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveServiceId($tenant->tenantId);

        $where = 'cr.tenant_id = :tenantId AND cr.service_id = :serviceId';
        $params = ['tenantId' => $tenant->tenantId, 'serviceId' => $serviceId];

        if ($status !== '' && $status !== 'all') {
            $where .= ' AND cr.status = :status';
            $params['status'] = $status;
        }

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT cr.id, cr.requested_minutes, cr.reason, cr.status, cr.granted_minutes, ' .
            'cr.decision_note, cr.reviewed_at, cr.created_at, ' .
            'requester.first_name AS requester_first_name, requester.last_name AS requester_last_name, ' .
            'reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name ' .
            'FROM nexa_call_credit_request cr ' .
            'LEFT JOIN `user` requester ON requester.id = cr.requested_by_user_id ' .
            'LEFT JOIN `user` reviewer ON reviewer.id = cr.reviewed_by_user_id ' .
            "WHERE {$where} ORDER BY cr.created_at DESC LIMIT 200"
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function decide(string $requestId, bool $approve, ?int $grantedMinutes, ?string $decisionNote): void
    {
        $this->requireTenantAdmin();

        $tenant = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();

        $lookup = $pdo->prepare(
            'SELECT service_id, requested_by_user_id, requested_minutes, period_key FROM nexa_call_credit_request ' .
            'WHERE id = :id AND tenant_id = :tenantId LIMIT 1'
        );
        $lookup->execute(['id' => $requestId, 'tenantId' => $tenant->tenantId]);
        $request = $lookup->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new BadRequest('Request not found.');
        }

        $status = $approve ? 'approved' : 'denied';
        $granted = $approve ? max(1, $grantedMinutes ?? (int) $request['requested_minutes']) : null;

        $update = $pdo->prepare(
            'UPDATE nexa_call_credit_request ' .
            'SET status = :status, reviewed_by_user_id = :reviewerId, reviewed_at = NOW(6), ' .
            'granted_minutes = :granted, decision_note = :note ' .
            "WHERE id = :id AND status = 'pending'"
        );
        $update->execute([
            'status' => $status,
            'reviewerId' => $this->user->getId(),
            'granted' => $granted,
            'note' => $decisionNote !== null ? trim($decisionNote) : null,
            'id' => $requestId,
        ]);

        if ($update->rowCount() !== 1) {
            // Already decided by someone else (double-approve race) - the
            // first decide() to win the guarded update is the only one that
            // ever grants minutes or notifies the requester.
            throw new BadRequest('This request has already been reviewed.');
        }

        if ($approve) {
            $grant = $pdo->prepare(
                'INSERT INTO nexa_tenant_credit_grant (tenant_id, service_id, period_key, granted_minutes) ' .
                'VALUES (:tenantId, :serviceId, :periodKey, :granted) ' .
                'ON DUPLICATE KEY UPDATE granted_minutes = granted_minutes + VALUES(granted_minutes)'
            );
            $grant->execute([
                'tenantId' => $tenant->tenantId,
                'serviceId' => (string) $request['service_id'],
                'periodKey' => (string) $request['period_key'],
                'granted' => $granted,
            ]);
        }

        $message = $approve
            ? 'Your request for more calling minutes was approved (' . $granted . ' minutes granted).'
            : 'Your request for more calling minutes was denied.';

        if ($decisionNote !== null && trim($decisionNote) !== '') {
            $message .= ' Note: ' . trim($decisionNote);
        }

        $this->entityManager->createEntity(Notification::ENTITY_TYPE, [
            'type' => Notification::TYPE_SYSTEM,
            'userId' => (string) $request['requested_by_user_id'],
            'data' => ['message' => $message],
        ]);
    }

    private function requireTenantAdmin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can review calling-minutes requests.');
        }
    }

    /** @return list<string> */
    private function tenantAdminUserIds(string $tenantId): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT id FROM `user` WHERE tenant_id = :tenantId AND type = 'admin' AND deleted = 0"
        );
        $statement->execute(['tenantId' => $tenantId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Mirrors CallService::resolveServiceId(). */
    private function resolveServiceId(string $tenantId): string
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT ts.service_id FROM nexa_tenant_service ts ' .
            'INNER JOIN nexa_service_definition sd ON sd.id = ts.service_id ' .
            'WHERE ts.tenant_id = :tenantId AND sd.service_key = :serviceKey AND ts.status = \'active\' LIMIT 1'
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceKey' => self::SERVICE_KEY]);
        $serviceId = $statement->fetchColumn();

        if (!is_string($serviceId) || $serviceId === '') {
            throw new Forbidden('Voice calling is not enabled for this workspace.');
        }

        return $serviceId;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
