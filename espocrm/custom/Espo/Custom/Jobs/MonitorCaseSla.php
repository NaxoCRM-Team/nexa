<?php
namespace Espo\Custom\Jobs;
use DateTimeImmutable; use Espo\Core\Job\JobDataLess; use Espo\Modules\Crm\Entities\CaseObj; use Espo\ORM\EntityManager;
/** Marks native Cases as breached and escalated inside the active tenant context. */
final class MonitorCaseSla implements JobDataLess {
    public function __construct(private EntityManager $entityManager) {}
    public function run(): void {
        $now = new DateTimeImmutable();
        $cases = $this->entityManager->getRDBRepositoryByClass(CaseObj::class)->where([
            'deleted' => false, 'slaStatus' => ['Running', 'Not Started'],
            'resolutionDueAt<' => $now->format('Y-m-d H:i:s'),
            'status!=' => ['Closed', 'Rejected', 'Duplicate'],
        ])->find();
        foreach ($cases as $case) {
            $case->set('slaStatus', 'Breached'); $case->set('escalationLevel', max(1, (int) $case->get('escalationLevel')));
            $case->set('escalatedAt', $now->format('Y-m-d H:i:s')); $case->set('escalationReason', 'Resolution SLA missed');
            $this->entityManager->saveEntity($case);
        }
    }
}
