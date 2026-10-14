<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Core\{Application,InjectableFactory};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext,TenantContextStore};
use Espo\Core\Exceptions\{Forbidden,NotFound};
use Espo\Custom\Tools\Automation\WorkflowService;
$app=new Application();$app->setupSystemUser();$container=$app->getContainer();$em=$container->getByClass(EntityManager::class);$factory=$container->getByClass(InjectableFactory::class);$contexts=$container->getByClass(TenantContextStore::class);$pdo=$em->getPDO();
$a=new TenantContext('30000000-0000-4000-8000-000000000001','isolation-alpha','workflow-ab-test');$b=new TenantContext('30000000-0000-4000-8000-000000000002','isolation-beta','workflow-ab-test');
$checks=0;$assert=static function(bool$condition,string$message)use(&$checks):void{++$checks;if(!$condition)throw new RuntimeException($message);};
$query=static function(string$sql,array$params)use($pdo):array{$s=$pdo->prepare($sql);$s->execute($params);return$s->fetchAll(PDO::FETCH_ASSOC);};
$tx=$em->getTransactionManager();$tx->start();
try{
    $suffix=bin2hex(random_bytes(5));$call=static fn(callable$fn)=>$contexts->runWith($a,$fn);
    $pdo->prepare("UPDATE nexa_tenant_service g INNER JOIN nexa_service_definition d ON d.id=g.service_id SET g.hard_limit_override=2000 WHERE g.tenant_id=? AND d.service_key='automation'")->execute([$a->tenantId]);
    $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=? AND status='published'")->execute([$a->tenantId,$a->serviceId]);
    $admin=$call(fn()=>$em->createEntity('User',['userName'=>'ab-'.$suffix,'emailAddress'=>'ab-'.$suffix.'@example.test','type'=>'admin','isActive'=>true]));$admin=$call(fn()=>$em->getEntityById('User',$admin->getId()));
    $contact=$call(fn()=>$em->createEntity('Contact',['firstName'=>'Router','lastName'=>'Acceptance']));
    $service=$factory->createWith(WorkflowService::class,['user'=>$admin]);$worker=$factory->create(WorkflowService::class);
    $definition=(object)['schemaVersion'=>1,'trigger'=>(object)['event'=>'manual','reenrollment'=>'after_completion'],'entry'=>'split','nodes'=>[(object)['id'=>'split','type'=>'ab_split','weightA'=>50,'yes'=>'a','no'=>'b'],(object)['id'=>'a','type'=>'end'],(object)['id'=>'b','type'=>'end']]];
    $draft=$call(fn()=>$service->save((object)['name'=>'Router '.$suffix,'definition'=>$definition]));$call(fn()=>$service->publish($draft['id'],1));
    $run=$call(fn()=>$service->enroll($draft['id'],$contact->getId(),'router-request-'.$suffix));$call(fn()=>$worker->processDue());
    $saved=$query('SELECT * FROM nexa_workflow_ab_assignment WHERE run_id=?',[$run['id']]);$assert(count($saved)===1,'The worker must persist one assignment before branch advancement.');
    $row=$saved[0];$next=$query('SELECT node_id FROM nexa_workflow_run WHERE id=?',[$run['id']])[0]['node_id'];$assert($next===strtolower($row['branch_key']),'The selected persisted branch must drive graph advancement.');
    $assert($row['tenant_id']===$a->tenantId&&$row['service_id']===$a->serviceId&&$row['contact_id']===$contact->getId(),'Routing evidence must retain exact scope and Contact.');
    $call(fn()=>$worker->processDue());$call(fn()=>$worker->processDue());$assert(count($query('SELECT * FROM nexa_workflow_ab_assignment WHERE run_id=?',[$run['id']]))===1,'Completed-run replay cannot rerandomize.');
    $replay=$call(fn()=>$service->enroll($draft['id'],$contact->getId(),'router-request-'.$suffix));$assert($replay['id']===$run['id'],'Enrollment key replay must keep the same persisted run.');
    $second=$call(fn()=>$service->enroll($draft['id'],$contact->getId(),'router-second-'.$suffix));$call(fn()=>$worker->processDue());
    $secondRow=$query('SELECT * FROM nexa_workflow_ab_assignment WHERE run_id=?',[$second['id']])[0];$assert($secondRow['assignment_sha256']===$row['assignment_sha256']&&$secondRow['branch_key']===$row['branch_key'],'Reenrollment in the same publication keeps a Contact in the same variant.');
    $blocked=false;try{$contexts->runWith($b,fn()=>$service->history($draft['id']));}catch(Forbidden|NotFound){$blocked=true;}$assert($blocked,'Other tenants cannot inspect routing evidence.');
    $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE id=?")->execute([$draft['id']]);
    $thirdDraft=$call(fn()=>$service->save((object)['name'=>'Revoked '.$suffix,'definition'=>$definition]));$call(fn()=>$service->publish($thirdDraft['id'],1));$third=$call(fn()=>$service->enroll($thirdDraft['id'],$contact->getId(),'router-revoked-'.$suffix));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$admin->getId()]);$call(fn()=>$worker->processDue());
    $assert($query('SELECT status FROM nexa_workflow_run WHERE id=?',[$third['id']])[0]['status']==='failed','Revoked execution administrator must fail before routing.');
    $assert($query('SELECT * FROM nexa_workflow_ab_assignment WHERE run_id=?',[$third['id']])===[],'Revocation must not consume an A/B assignment.');
    echo 'Native workflow A/B routing checks passed: '.$checks." (rollback-only).\n";
}finally{if($tx->isStarted())$tx->rollback();}
