<?php

namespace Espo\Custom\Tools\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Tenant\ServiceEntitlementChecker;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;
use PDO;

final class TenantDashboardService
{
    private const RANGE_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90, 'all' => null];

    public function __construct(
        private TenantContextStore $tenantContextStore,
        private ServiceEntitlementChecker $entitlements,
        private Acl $acl,
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    /** @return array<string, mixed> */
    public function getSummary(string $range): array
    {
        $range = array_key_exists($range, self::RANGE_DAYS) ? $range : '30d';

        if (!$this->entitlements->isEnabled('crm')) {
            throw new Forbidden('CRM is not enabled for this workspace.');
        }

        $tenant = $this->tenantContextStore->require();
        $serviceId = $this->resolveCrmServiceId($tenant->tenantId);
        $from = $this->rangeStart($range);
        // Aggregates use direct SQL, so require workspace-wide ACL before querying scoped records.
        $permissions = [
            'accounts' => $this->canReadAll('Account'),
            'contacts' => $this->canReadAll('Contact'),
            'leads' => $this->canReadAll('Lead'),
            'opportunities' => $this->canReadAll('Opportunity'),
            'tasks' => $this->canReadAll('Task'),
            'meetings' => $this->canReadAll('Meeting'),
        ];

        if (!in_array(true, $permissions, true)) {
            throw new Forbidden('Dashboard data is not available for this role.');
        }

        $metrics = [
            'accounts' => $this->metric($permissions['accounts'], 'account', $tenant->tenantId, $serviceId, $from),
            'contacts' => $this->metric($permissions['contacts'], 'contact', $tenant->tenantId, $serviceId, $from),
            'leads' => $this->metric($permissions['leads'], 'lead', $tenant->tenantId, $serviceId, $from),
            'openOpportunities' => $this->metric(
                $permissions['opportunities'],
                'opportunity',
                $tenant->tenantId,
                $serviceId,
                $from,
                "stage NOT IN ('Closed Won', 'Closed Lost')"
            ),
            'openTasks' => $this->metric(
                $permissions['tasks'],
                'task',
                $tenant->tenantId,
                $serviceId,
                null,
                "status NOT IN ('Completed', 'Deferred')"
            ),
        ];
        $pipeline = $this->pipeline($permissions['opportunities'], $tenant->tenantId, $serviceId);
        $activities = $this->activities($permissions, $tenant->tenantId, $serviceId);
        $pipelineValue = array_sum(array_column($pipeline, 'amount'));
        $visibleValues = array_map(
            static fn (array $metric): int => $metric['available'] ? $metric['value'] : 0,
            $metrics
        );

        return [
            'tenant' => ['id' => $tenant->tenantId, 'slug' => $tenant->slug, 'displayName' => $tenant->displayName],
            'service' => 'crm',
            'range' => $range,
            'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'metrics' => $metrics + [
                'pipelineValue' => [
                    'available' => $permissions['opportunities'] && $this->acl->checkField('Opportunity', 'amount'),
                    'value' => $pipelineValue,
                ],
            ],
            'currency' => (string) ($this->config->get('baseCurrency') ?: 'USD'),
            'pipeline' => $pipeline,
            'activities' => $activities,
            'permissions' => $permissions,
            'empty' => array_sum($visibleValues) === 0 && $pipelineValue === 0.0 && $activities === [],
        ];
    }

    private function canReadAll(string $scope): bool
    {
        return $this->acl->checkScope($scope, Table::ACTION_READ) && $this->acl->checkReadAll($scope);
    }

    private function resolveCrmServiceId(string $tenantId): string
    {
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT ts.service_id FROM nexa_tenant_service ts " .
            "INNER JOIN nexa_service_definition sd ON sd.id = ts.service_id " .
            "WHERE ts.tenant_id = :tenantId AND sd.service_key = 'crm' AND ts.status = 'active' LIMIT 1"
        );
        $statement->execute(['tenantId' => $tenantId]);
        $serviceId = $statement->fetchColumn();

        if (!is_string($serviceId) || $serviceId === '') {
            throw new Forbidden('CRM is not enabled for this workspace.');
        }

        return $serviceId;
    }

    private function rangeStart(string $range): ?string
    {
        $days = self::RANGE_DAYS[$range];

        return $days === null ? null : (new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** @return array{available: bool, value: int} */
    private function metric(
        bool $available,
        string $table,
        string $tenantId,
        string $serviceId,
        ?string $from,
        ?string $extraWhere = null,
    ): array {
        if (!$available) {
            return ['available' => false, 'value' => 0];
        }

        $where = 'tenant_id = :tenantId AND service_id = :serviceId AND deleted = 0';
        $parameters = ['tenantId' => $tenantId, 'serviceId' => $serviceId];

        if ($from !== null) {
            $where .= ' AND created_at >= :fromDate';
            $parameters['fromDate'] = $from;
        }
        if ($extraWhere !== null) {
            $where .= ' AND ' . $extraWhere;
        }

        $statement = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $statement->execute($parameters);

        return ['available' => true, 'value' => (int) $statement->fetchColumn()];
    }

    /** @return array<int, array{stage: string, count: int, amount: float}> */
    private function pipeline(bool $available, string $tenantId, string $serviceId): array
    {
        if (!$available || !$this->acl->checkField('Opportunity', 'amount')) {
            return [];
        }

        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT stage, COUNT(*) AS record_count, COALESCE(SUM(amount), 0) AS total_amount " .
            "FROM opportunity WHERE tenant_id = :tenantId AND service_id = :serviceId AND deleted = 0 " .
            "AND stage NOT IN ('Closed Won', 'Closed Lost') GROUP BY stage ORDER BY total_amount DESC"
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId]);

        return array_map(static fn (array $row): array => [
            'stage' => (string) $row['stage'],
            'count' => (int) $row['record_count'],
            'amount' => (float) $row['total_amount'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string, bool> $permissions @return array<int, array<string, mixed>> */
    private function activities(array $permissions, string $tenantId, string $serviceId): array
    {
        $queries = [];
        $parameters = ['tenantId' => $tenantId, 'serviceId' => $serviceId];

        if ($permissions['tasks']) {
            $queries[] = "SELECT id, name, 'Task' AS entity_type, date_start FROM task " .
                "WHERE tenant_id = :tenantId AND service_id = :serviceId AND deleted = 0 " .
                "AND status NOT IN ('Completed', 'Deferred') AND date_start >= UTC_TIMESTAMP()";
        }
        if ($permissions['meetings']) {
            $queries[] = "SELECT id, name, 'Meeting' AS entity_type, date_start FROM meeting " .
                "WHERE tenant_id = :tenantId AND service_id = :serviceId AND deleted = 0 " .
                "AND status = 'Planned' AND date_start >= UTC_TIMESTAMP()";
        }
        if ($queries === []) {
            return [];
        }

        $statement = $this->entityManager->getPDO()->prepare(
            implode(' UNION ALL ', $queries) . ' ORDER BY date_start ASC LIMIT 6'
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'entityType' => (string) $row['entity_type'],
            'dateStart' => (string) $row['date_start'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}
