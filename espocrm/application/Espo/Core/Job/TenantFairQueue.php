<?php

namespace Espo\Core\Job;

use Espo\Core\Job\Job\Status;
use Espo\Core\Job\QueueProcessor\Params;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\DateTime;
use Espo\Entities\Job;
use Espo\ORM\Collection;
use Throwable;

/** Coordinates selection turns, not native job claims or execution. */
final class TenantFairQueue
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $contexts,
    ) {}

    /** @param callable(?string, int, int): Collection<Job> $fetch */
    public function select(Params $params, callable $fetch): Collection
    {
        $limit = $params->getLimit();
        if ($limit < 0) {
            throw new \InvalidArgumentException('A queue portion limit cannot be negative.');
        }
        if ($limit === 0 || !$this->contexts->isPlatform()) {
            return $fetch(null, 0, $limit);
        }

        $pdo = $this->entityManager->getPDO();
        $ownTransaction = !$pdo->inTransaction();
        $scope = hash('sha256', json_encode([$params->getQueue(), $params->getGroup()], JSON_THROW_ON_ERROR));
        if ($ownTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT nexa_job_selection');
        }

        try {
            // Selectors serialize only their queue/group turn; native claims still decide execution.
            $insert = $pdo->prepare('INSERT INTO nexa_job_dispatch_rotation (scope_key) VALUES (?)
                ON DUPLICATE KEY UPDATE scope_key = VALUES(scope_key)');
            $insert->execute([$scope]);
            $cursor = $pdo->prepare('SELECT last_tenant_id FROM nexa_job_dispatch_rotation WHERE scope_key = ? FOR UPDATE');
            $cursor->execute([$scope]);
            $last = (string) $cursor->fetchColumn();
            $cursor->closeCursor();

            $tenants = [];
            $heads = $this->entityManager->getRDBRepositoryByClass(Job::class)
                ->select(['tenantId'])
                ->where([
                    'status' => Status::PENDING,
                    'executeTime<=' => DateTime::getSystemNowString(),
                    'queue' => $params->getQueue(),
                    'group' => $params->getGroup(),
                ])
                ->group(['tenantId'])
                ->order('tenantId')
                ->find();
            foreach ($heads as $head) {
                $tenants[] = (string) $head->get('tenantId');
            }
            $tenants = array_merge(
                array_values(array_filter($tenants, fn ($id) => strcmp($id, $last) > 0)),
                array_values(array_filter($tenants, fn ($id) => strcmp($id, $last) <= 0)),
            );

            $selected = [];
            $offsets = [];
            while ($tenants && count($selected) < $limit) {
                foreach ($tenants as $index => $tenantId) {
                    $found = false;
                    foreach ($fetch($tenantId, $offsets[$tenantId] ?? 0, 1) as $job) {
                        $selected[] = $job;
                        $offsets[$tenantId] = ($offsets[$tenantId] ?? 0) + 1;
                        $last = $tenantId;
                        $found = true;
                        break;
                    }
                    if (!$found) {
                        unset($tenants[$index]);
                    }
                    if (count($selected) >= $limit) {
                        break;
                    }
                }
            }
            if ($selected) {
                $update = $pdo->prepare('UPDATE nexa_job_dispatch_rotation SET last_tenant_id = ? WHERE scope_key = ?');
                $update->execute([$last, $scope]);
            }
            if ($ownTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT nexa_job_selection');
            }
            return $this->entityManager->getCollectionFactory()->create(Job::ENTITY_TYPE, $selected);
        } catch (Throwable $error) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT nexa_job_selection');
                $pdo->exec('RELEASE SAVEPOINT nexa_job_selection');
            }
            throw $error;
        }
    }
}
