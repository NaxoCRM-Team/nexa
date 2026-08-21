<?php

namespace Espo\Custom\Tools\ScheduledJobs;

use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Every native scheduled job used to get exactly one `scheduled_job` row,
 * created under whatever tenant happened to be ambient during `rebuild`
 * (the legacy-local placeholder tenant). Since ScheduleProcessor runs each
 * job's preparator inside that row's own tenant context, jobs that look up
 * tenant-owned data (mailboxes, reminders, notifications, ...) could never
 * see any real tenant's data. This provisions one row per real tenant for
 * those jobs, while genuinely tenant-agnostic jobs keep a single row.
 *
 * Note: only some native jobs are metadata `isSystem`-flagged and managed
 * by the stock create/prune loop (syncGlobal, unchanged from stock). Others
 * (e.g. CheckEmailAccounts, Cleanup, AuthTokenControl) were seeded once by
 * the installer and are never revisited by that loop at all. To cover both,
 * the per-tenant catalog is read from whatever currently exists on the
 * legacy-local tenant (the canonical "what native jobs exist" list) rather
 * than from metadata directly.
 */
final class ScheduledJobProvisioner
{
    private const LEGACY_LOCAL_ID = '00000000-0000-4000-8000-000000000001';

    /**
     * Jobs that touch no tenant-owned data (external APIs, or already-global
     * raw-SQL sweeps) and must stay single-instance. Everything else in the
     * legacy-local catalog defaults to tenant-scoped - a fail-safe default
     * so a future native job doesn't silently repeat this bug.
     */
    private const GLOBAL_JOB_NAMES = [
        'Dummy',
        'CheckNewVersion',
        'CheckNewExtensionVersion',
        'ReleaseStaleCallReservations',
    ];

    public function __construct(
        private Metadata $metadata,
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
    ) {}

    /** Unchanged stock behavior: metadata-driven create/prune on legacy-local. */
    public function syncGlobal(): void
    {
        $jobDefs = array_merge(
            $this->metadata->get(['entityDefs', 'ScheduledJob', 'jobs'], []), // for bc
            $this->metadata->get(['app', 'scheduledJobs'], [])
        );

        $systemJobNameList = [];

        foreach ($jobDefs as $jobName => $defs) {
            if (!$jobName || empty($defs['isSystem']) || empty($defs['scheduling'])) {
                continue;
            }

            $systemJobNameList[] = $jobName;

            $sj = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where([
                    'job' => $jobName,
                    'status' => ScheduledJob::STATUS_ACTIVE,
                    'scheduling' => $defs['scheduling'],
                ])
                ->findOne();

            if ($sj) {
                continue;
            }

            $existingJob = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => $jobName])
                ->findOne();

            if ($existingJob) {
                $this->entityManager->removeEntity($existingJob);
            }

            $name = !empty($defs['name']) ? $defs['name'] : $jobName;

            $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
                'job' => $jobName,
                'status' => ScheduledJob::STATUS_ACTIVE,
                'scheduling' => $defs['scheduling'],
                'isInternal' => true,
                'name' => $name,
            ]);
        }

        $internalScheduledJobList = $this->entityManager
            ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
            ->where(['isInternal' => true])
            ->find();

        foreach ($internalScheduledJobList as $scheduledJob) {
            $jobName = $scheduledJob->get('job');

            if (!in_array($jobName, $systemJobNameList)) {
                $this->entityManager
                    ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                    ->deleteFromDb($scheduledJob->getId());
            }
        }
    }

    public function syncForTenant(string $tenantId, string $slug, string $displayName): void
    {
        $catalog = $this->tenantScopedCatalog();

        $this->tenantContextStore->runWith(
            new TenantContext($tenantId, $slug, 'scheduled-job-provisioning', $displayName),
            function () use ($catalog): void {
                $this->createMissing($catalog);
            }
        );
    }

    /** @return array<int, array{id: string, slug: string, display_name: string}> */
    public function listRealTenants(): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT id, slug, display_name FROM nexa_tenant WHERE status = 'active' AND id != ?"
        );
        $statement->execute([self::LEGACY_LOCAL_ID]);

        /** @var array<int, array{id: string, slug: string, display_name: string}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The live catalog of native jobs that need per-tenant rows, read off
     * legacy-local's own rows rather than metadata (see class docblock).
     *
     * @return array<string, array{scheduling: string, name: string, isInternal: bool}>
     */
    private function tenantScopedCatalog(): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT job, scheduling, name, is_internal FROM scheduled_job " .
            "WHERE tenant_id = ? AND status = 'Active' AND deleted = 0"
        );
        $statement->execute([self::LEGACY_LOCAL_ID]);

        $catalog = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $jobName = $row['job'];

            if (!$jobName || in_array($jobName, self::GLOBAL_JOB_NAMES, true)) {
                continue;
            }

            $catalog[$jobName] = [
                'scheduling' => $row['scheduling'],
                'name' => $row['name'],
                'isInternal' => (bool) $row['is_internal'],
            ];
        }

        return $catalog;
    }

    /** @param array<string, array{scheduling: string, name: string, isInternal: bool}> $catalog */
    private function createMissing(array $catalog): void
    {
        foreach ($catalog as $jobName => $defs) {
            $existing = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where([
                    'job' => $jobName,
                    'status' => ScheduledJob::STATUS_ACTIVE,
                    'scheduling' => $defs['scheduling'],
                ])
                ->findOne();

            if ($existing) {
                continue;
            }

            $stale = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => $jobName])
                ->findOne();

            if ($stale) {
                $this->entityManager->removeEntity($stale);
            }

            $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
                'job' => $jobName,
                'status' => ScheduledJob::STATUS_ACTIVE,
                'scheduling' => $defs['scheduling'],
                'isInternal' => $defs['isInternal'],
                'name' => $defs['name'],
            ]);
        }
    }
}
