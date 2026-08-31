<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\CaseService;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Tenant\ServiceEntitlementChecker;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\CaseObj;
use Espo\ORM\EntityManager;
use PDO;
use stdClass;

/** Adds SLA behavior around the native Case lifecycle and assignment model. */
final class CaseSlaService
{
    private const CLOSED_STATUSES = ['Closed', 'Rejected', 'Duplicate'];

    public function __construct(
        private TenantContextStore $tenantContextStore,
        private ServiceEntitlementChecker $entitlements,
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
    ) {}

    public function initialize(CaseObj $case): void
    {
        $context = $this->context();
        $policy = $this->matchingPolicy(
            $context,
            (string) ($case->get('priority') ?: 'Normal'),
            (string) ($case->get('category') ?: '')
        );
        if (!$policy) return;

        $now = new DateTimeImmutable();
        $status = (string) $case->get('status');
        $case->set('slaPolicyId', $policy['id']);
        $case->set('firstResponseDueAt', $now->modify('+' . $policy['first_response_minutes'] . ' minutes')->format('Y-m-d H:i:s'));
        $case->set('resolutionDueAt', $now->modify('+' . $policy['resolution_minutes'] . ' minutes')->format('Y-m-d H:i:s'));

        if (in_array($status, self::CLOSED_STATUSES, true)) {
            $case->set('slaStatus', 'Met');
            $case->set('resolvedAt', $now->format('Y-m-d H:i:s'));
            return;
        }

        if ($status === 'Pending') {
            $case->set('slaStatus', 'Paused');
            $case->set('slaPausedAt', $now->format('Y-m-d H:i:s'));
            return;
        }

        $case->set('slaStatus', 'Running');
    }

    public function prepareUpdate(CaseObj $case): void
    {
        if (!$case->isAttributeChanged('status')) return;

        $now = new DateTimeImmutable();
        $status = (string) $case->get('status');
        $previousStatus = (string) $case->getFetched('status');

        if (in_array($status, self::CLOSED_STATUSES, true)) {
            if (!$case->get('resolvedAt')) $case->set('resolvedAt', $now->format('Y-m-d H:i:s'));
            $due = $this->date($case->get('resolutionDueAt'));
            $case->set('slaStatus', $due && $now > $due ? 'Breached' : 'Met');
            return;
        }

        if ($status === 'Pending' && $previousStatus !== 'Pending') {
            $case->set('slaPausedAt', $now->format('Y-m-d H:i:s'));
            $case->set('slaStatus', 'Paused');
            return;
        }

        if ($previousStatus === 'Pending' && $status !== 'Pending') {
            $pausedAt = $this->date($case->getFetched('slaPausedAt'));
            if ($pausedAt) {
                $seconds = max(0, $now->getTimestamp() - $pausedAt->getTimestamp());
                $case->set('slaPausedSeconds', (int) $case->get('slaPausedSeconds') + $seconds);
                foreach (['firstResponseDueAt', 'resolutionDueAt'] as $field) {
                    $due = $this->date($case->get($field));
                    if ($due) $case->set($field, $due->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s'));
                }
            }
            $case->set('slaPausedAt', null);
            $case->set('slaStatus', 'Running');
        }
    }

    /** @return array<string, mixed> */
    public function recordResponse(string $caseId): array
    {
        $case = $this->caseForAction($caseId, Table::ACTION_EDIT);
        if (!$case->get('firstRespondedAt')) {
            $now = new DateTimeImmutable();
            $case->set('firstRespondedAt', $now->format('Y-m-d H:i:s'));
            $due = $this->date($case->get('firstResponseDueAt'));
            if ($due && $now > $due) {
                $case->set('slaStatus', 'Breached');
                $case->set('escalationLevel', max(1, (int) $case->get('escalationLevel')));
                $case->set('escalatedAt', $now->format('Y-m-d H:i:s'));
                $case->set('escalationReason', 'First response SLA missed');
            }
            $this->entityManager->saveEntity($case);
        }
        return $this->summary($case);
    }

    /** @return array<string, mixed> */
    public function summaryForCase(string $caseId): array
    {
        return $this->summary($this->caseForAction($caseId, Table::ACTION_READ));
    }

    /** @return list<array<string, mixed>> */
    public function listPolicies(): array
    {
        $context = $this->context();
        if (!$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can manage SLA policies.');
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT * FROM nexa_case_sla_policy WHERE tenant_id=? AND service_id=? AND is_active=1 ' .
            'ORDER BY is_default DESC, FIELD(priority,\'Urgent\',\'High\',\'Normal\',\'Low\'), name'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map([$this, 'serializePolicy'], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string, mixed>> */
    public function savePolicies(stdClass $data): array
    {
        $context = $this->context();
        if (!$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can manage SLA policies.');
        $items = is_array($data->policies ?? null) ? $data->policies : [];
        if ($items === []) throw new BadRequest('Add at least one SLA policy.');

        $this->entityManager->getPDO()->beginTransaction();
        try {
            $this->entityManager->getPDO()->prepare(
                'UPDATE nexa_case_sla_policy SET is_active=0,is_default=0 WHERE tenant_id=? AND service_id=?'
            )->execute([$context->tenantId, $context->serviceId]);
            $defaultSeen = false;
            foreach ($items as $item) {
                $item = is_object($item) ? $item : (object) $item;
                $name = trim((string) ($item->name ?? ''));
                $priority = trim((string) ($item->priority ?? '')) ?: null;
                $response = (int) ($item->firstResponseMinutes ?? 0);
                $resolution = (int) ($item->resolutionMinutes ?? 0);
                $isDefault = !$defaultSeen && !empty($item->isDefault);
                if ($name === '' || mb_strlen($name) > 120 || $response < 1 || $resolution < 1) {
                    throw new BadRequest('Each SLA policy needs a name and positive response and resolution minutes.');
                }
                if ($priority && !in_array($priority, ['Urgent', 'High', 'Normal', 'Low'], true)) {
                    throw new BadRequest('Select a valid SLA priority.');
                }
                $id = preg_match('/^[a-f0-9-]{36}$/i', (string) ($item->id ?? '')) ? (string) $item->id : $this->uuid();
                $sql = 'INSERT INTO nexa_case_sla_policy (id,tenant_id,service_id,name,priority,category,first_response_minutes,resolution_minutes,escalation_minutes,pause_statuses_json,is_default,is_active) ' .
                    'VALUES (?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),priority=VALUES(priority),category=VALUES(category),' .
                    'first_response_minutes=VALUES(first_response_minutes),resolution_minutes=VALUES(resolution_minutes),escalation_minutes=VALUES(escalation_minutes),' .
                    'pause_statuses_json=VALUES(pause_statuses_json),is_default=VALUES(is_default),is_active=1';
                $this->entityManager->getPDO()->prepare($sql)->execute([
                    $id, $context->tenantId, $context->serviceId, $name, $priority,
                    trim((string) ($item->category ?? '')) ?: null, $response, $resolution,
                    max(0, (int) ($item->escalationMinutes ?? 0)) ?: null, '["Pending"]', $isDefault ? 1 : 0,
                ]);
                $defaultSeen = $defaultSeen || $isDefault;
            }
            if (!$defaultSeen) {
                $this->entityManager->getPDO()->prepare(
                    'UPDATE nexa_case_sla_policy SET is_default=1 WHERE tenant_id=? AND service_id=? AND is_active=1 ORDER BY name LIMIT 1'
                )->execute([$context->tenantId, $context->serviceId]);
            }
            $this->entityManager->getPDO()->commit();
        } catch (\Throwable $e) {
            $this->entityManager->getPDO()->rollBack();
            throw $e;
        }
        return $this->listPolicies();
    }

    private function caseForAction(string $id, string $action): CaseObj
    {
        $this->context();
        $case = $this->entityManager->getRDBRepositoryByClass(CaseObj::class)->getById($id);
        if (!$case || !$this->acl->check($case, $action)) throw new Forbidden('Case is unavailable.');
        return $case;
    }

    /** @return array<string, mixed>|null */
    private function matchingPolicy(TenantContext $context, string $priority, string $category): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT * FROM nexa_case_sla_policy WHERE tenant_id=? AND service_id=? AND is_active=1 ' .
            'AND (priority=? OR priority IS NULL) AND (category=? OR category IS NULL) ' .
            'ORDER BY (priority=?) DESC,(category=?) DESC,is_default DESC LIMIT 1'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $priority, $category, $priority, $category]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function summary(CaseObj $case): array
    {
        return [
            'id' => $case->getId(), 'slaStatus' => $case->get('slaStatus'),
            'firstResponseDueAt' => $case->get('firstResponseDueAt'), 'resolutionDueAt' => $case->get('resolutionDueAt'),
            'firstRespondedAt' => $case->get('firstRespondedAt'), 'resolvedAt' => $case->get('resolvedAt'),
            'escalationLevel' => (int) $case->get('escalationLevel'), 'escalationReason' => $case->get('escalationReason'),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serializePolicy(array $row): array
    {
        return [
            'id' => $row['id'], 'name' => $row['name'], 'priority' => $row['priority'], 'category' => $row['category'],
            'firstResponseMinutes' => (int) $row['first_response_minutes'],
            'resolutionMinutes' => (int) $row['resolution_minutes'],
            'escalationMinutes' => $row['escalation_minutes'] === null ? null : (int) $row['escalation_minutes'],
            'isDefault' => (bool) $row['is_default'],
        ];
    }

    private function context(): TenantContext
    {
        if (!$this->entitlements->isEnabled('crm')) throw new Forbidden('CRM is not enabled for this workspace.');
        return $this->tenantContextStore->require();
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        return $value === '' ? null : new DateTimeImmutable($value);
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
            '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}
