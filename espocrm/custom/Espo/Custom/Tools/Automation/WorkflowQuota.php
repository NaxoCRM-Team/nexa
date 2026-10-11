<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use PDO;

/** Native subscription limits; no second billing or usage store. */
final class WorkflowQuota
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts) {}

    public function limits(bool $lock = false): array
    {
        $tenant=$this->contexts->require()->tenantId;$pdo=$this->em->getPDO();
        if ($lock) {
            if (!$pdo->inTransaction()) throw new \LogicException('Workflow capacity requires a transaction.');
            // All workflow capacity mutations acquire this before workflow/run locks.
            $query=$pdo->prepare('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE');$query->execute([$tenant]);
            if (!$query->fetchColumn()) throw new Forbidden('Tenant unavailable.');
        }
        $query=$pdo->prepare("SELECT d.id AS service_id,COALESCE(g.hard_limit_override,p.hard_limit) AS workflow_limit,p.configuration_json AS plan_configuration,g.configuration_json AS tenant_configuration FROM nexa_service_definition d INNER JOIN nexa_tenant_service g ON g.service_id=d.id AND g.tenant_id=? AND g.status='active' AND (g.starts_at IS NULL OR g.starts_at<=UTC_TIMESTAMP(6)) AND (g.ends_at IS NULL OR g.ends_at>UTC_TIMESTAMP(6)) INNER JOIN nexa_tenant_subscription s ON s.tenant_id=g.tenant_id AND (s.status='active' OR (s.status='trialing' AND s.trial_ends_at>UTC_TIMESTAMP(6))) INNER JOIN nexa_plan_definition pd ON pd.id=s.plan_id AND pd.status='active' INNER JOIN nexa_plan_service p ON p.plan_id=s.plan_id AND p.service_id=d.id AND p.is_enabled=1 WHERE d.service_key='automation' AND d.status='active'" . ($lock?' LOCK IN SHARE MODE':''));
        // Shared plan rows stay stable without serializing different tenants' workers.
        $context=$this->contexts->require();
        $enabled=$pdo->prepare("SELECT 1 FROM nexa_tenant_service g INNER JOIN nexa_tenant t ON t.id=g.tenant_id AND t.status='active' INNER JOIN nexa_service_definition d ON d.id=g.service_id AND d.status='active' WHERE g.tenant_id=? AND g.service_id=? AND g.status='active' AND (g.starts_at IS NULL OR g.starts_at<=UTC_TIMESTAMP(6)) AND (g.ends_at IS NULL OR g.ends_at>UTC_TIMESTAMP(6))");$enabled->execute([$tenant,$context->serviceId]);
        if(!$enabled->fetchColumn())throw new Forbidden('The workspace service is unavailable.');
        $query->execute([$tenant]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['workflow_limit']===null) throw new Forbidden('An active Automation entitlement with a configured limit is required.');
        $configuration=[];
        foreach (['plan_configuration','tenant_configuration'] as $key) {
            $decoded=json_decode($row[$key] ?: '{}',true,32,JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new Forbidden('Invalid Automation capacity configuration.');
            $configuration=array_replace($configuration,$decoded);
        }
        $limits=['serviceId'=>$row['service_id'],'workflows'=>(int)$row['workflow_limit'],'maxActiveRuns'=>10000,'maxStepsPerDay'=>100000,'maxConcurrentWebhooks'=>2];
        foreach (['maxActiveRuns','maxStepsPerDay','maxConcurrentWebhooks'] as $name) if (array_key_exists($name,$configuration)) {
            if (!is_int($configuration[$name]) || $configuration[$name]<1 || $configuration[$name]>1000000) throw new Forbidden('Invalid Automation capacity configuration.');
            $limits[$name]=$configuration[$name];
        }
        return $limits;
    }

    public function requireCreation(array $limits): void
    {
        $query=$this->em->getPDO()->prepare("SELECT id FROM nexa_workflow WHERE tenant_id=? AND status<>'archived' FOR UPDATE");
        $query->execute([$this->contexts->require()->tenantId]);
        if (count($query->fetchAll(PDO::FETCH_COLUMN)) >= $limits['workflows']) throw new WorkflowCapacityException('Workflow plan limit reached. Archive an unused workflow or change the plan.');
    }

    public function requireEnrollment(array $limits): void
    {
        $query=$this->em->getPDO()->prepare("SELECT id FROM nexa_workflow_run WHERE tenant_id=? AND status IN ('ready','waiting') FOR UPDATE");
        $query->execute([$this->contexts->require()->tenantId]);
        if (count($query->fetchAll(PDO::FETCH_COLUMN)) >= $limits['maxActiveRuns']) throw new WorkflowCapacityException('Active workflow enrollment capacity reached. Automatic events will be retried later.');
    }

    public function consumeStep(array $limits): bool
    {
        $pdo=$this->em->getPDO();$tenant=$this->contexts->require()->tenantId;
        $period='workflow.steps:'.$pdo->query('SELECT UTC_DATE()')->fetchColumn();
        $pdo->prepare('INSERT IGNORE INTO nexa_usage_counter (tenant_id,service_id,period_key,quantity) VALUES (?,?,?,0)')->execute([$tenant,$limits['serviceId'],$period]);
        $query=$pdo->prepare('UPDATE nexa_usage_counter SET quantity=quantity+1 WHERE tenant_id=? AND service_id=? AND period_key=? AND quantity<?');
        $query->execute([$tenant,$limits['serviceId'],$period,$limits['maxStepsPerDay']]);
        return $query->rowCount()===1;
    }
}
