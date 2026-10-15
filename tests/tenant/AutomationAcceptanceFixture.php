<?php
declare(strict_types=1);

// CLI-only committed fixtures: independent HTTP/worker connections cannot see rollback fixtures.
if (PHP_SAPI !== 'cli' || getenv('NEXA_AUTOMATION_ACCEPTANCE') !== '1') {
    throw new RuntimeException('Explicit local automation acceptance opt-in is required.');
}
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Custom\Tools\Automation\{WorkflowService, WorkflowWebhookService};

$input = json_decode(trim((string) fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$em = $container->getByClass(EntityManager::class);
$factory = $container->getByClass(InjectableFactory::class);
$contexts = $container->getByClass(TenantContextStore::class);
$pdo = $em->getPDO();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
});
$pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
$command = $input['command'];
$tenants = $input['tenants'];
foreach ($tenants as $tenant) {
    if (!preg_match('/^[0-9a-f-]{36}$/D', $tenant['id']) || !preg_match('/^automation-acceptance-[a-f0-9]{12}-[ab]$/D', $tenant['slug'])) {
        throw new RuntimeException('Only uniquely named acceptance tenants are allowed.');
    }
}
$execute = static function (string $sql, array $params = []) use ($pdo): PDOStatement {
    $query = $pdo->prepare($sql); $query->execute($params); return $query;
};
$atomic = static function (callable $fn) use ($pdo): mixed {
    $pdo->beginTransaction();
    try { $result = $fn(); $pdo->commit(); return $result; }
    catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
};

if ($command === 'list-fixtures') {
    echo json_encode($execute("SELECT id,slug FROM nexa_tenant WHERE display_name='Automation acceptance fixture' AND slug REGEXP '^automation-acceptance-[a-f0-9]{12}-[ab]$' AND status='active'")->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    exit;
}

if ($command === 'setup') {
    $result = $atomic(function () use ($tenants, $execute, $contexts, $em, $pdo): array {
        $plan = $execute("SELECT p.plan_id FROM nexa_plan_service p INNER JOIN nexa_service_definition d ON d.id=p.service_id AND d.service_key='automation' INNER JOIN nexa_plan_definition pd ON pd.id=p.plan_id AND pd.status='active' WHERE p.is_enabled=1 AND p.hard_limit IS NOT NULL ORDER BY p.plan_id LIMIT 1")->fetchColumn();
        if (!$plan) throw new RuntimeException('An active Automation plan is required.');
        $crm = $execute("SELECT id FROM nexa_service_definition WHERE service_key='crm' AND status='active'")->fetchColumn();
        if (!$crm) throw new RuntimeException('An active CRM service is required.');
        $result = [];
        foreach ($tenants as $tenant) {
            $execute("INSERT INTO nexa_tenant (id,slug,display_name,status,region,timezone) VALUES (?,?,'Automation acceptance fixture','active','local','UTC')", [$tenant['id'],$tenant['slug']]);
            $execute("INSERT INTO nexa_tenant_subscription (id,tenant_id,plan_id,status) VALUES (UUID(),?,?,'active')", [$tenant['id'],$plan]);
            $execute("INSERT INTO nexa_tenant_service (tenant_id,service_id,status,hard_limit_override) SELECT ?,id,'active',100 FROM nexa_service_definition WHERE service_key IN ('crm','automation') AND status='active'", [$tenant['id']]);
            $context = new TenantContext($tenant['id'],$tenant['slug'],'automation-acceptance','Automation acceptance fixture',$crm);
            $result[] = $contexts->runWith($context, function () use ($tenant,$em,$execute,$crm): array {
                $password = bin2hex(random_bytes(24));
                $email = $tenant['slug'].'@example.test';
                $user = $em->createEntity('User',['userName'=>$tenant['slug'],'emailAddress'=>$email,'loginEmail'=>$email,'firstName'=>'Acceptance','lastName'=>'Administrator','type'=>'admin','isActive'=>true]);
                $execute('UPDATE user SET password=?,login_email=? WHERE id=? AND tenant_id=?', [password_hash($password,PASSWORD_BCRYPT),$email,$user->getId(),$tenant['id']]);
                $contact = $em->createEntity('Contact',['firstName'=>'Acceptance','lastName'=>$tenant['slug'],'leadScore'=>10]);
                $hook = $em->createEntity('Webhook',['url'=>'https://example.com/automation-acceptance','event'=>'Contact.create','isActive'=>true,'secretKey'=>bin2hex(random_bytes(24))]);
                return [...$tenant,'serviceId'=>$crm,'userId'=>$user->getId(),'username'=>$email,'password'=>$password,'contactId'=>$contact->getId(),'webhookId'=>$hook->getId()];
            });
        }
        return $result;
    });
    echo json_encode($result, JSON_THROW_ON_ERROR); exit;
}

foreach ($tenants as $tenant) {
    $row = $execute('SELECT slug,display_name FROM nexa_tenant WHERE id=?',[$tenant['id']])->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['slug'] !== $tenant['slug'] || $row['display_name'] !== 'Automation acceptance fixture')) throw new RuntimeException('Fixture ownership does not match.');
    if (!$row && $command !== 'cleanup') throw new RuntimeException('Acceptance fixture is unavailable.');
}

if ($command === 'cleanup') {
    $atomic(function () use ($tenants,$execute): void {
        $fixtureQueue='fair-'.substr(hash('sha256',$tenants[0]['id']),0,20);
        foreach ([null,'other'] as $group) {
            $execute('DELETE FROM nexa_job_dispatch_rotation WHERE scope_key=?',[hash('sha256',json_encode([$fixtureQueue,$group],JSON_THROW_ON_ERROR))]);
        }
        $tables = $execute("SELECT DISTINCT c.TABLE_NAME FROM information_schema.COLUMNS c INNER JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE='BASE TABLE' WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME='tenant_id'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tenants as $tenant) {
            // Native login/CRM audit is immutable, including test evidence. Never disable its triggers.
            $pending = array_values(array_diff($tables,['nexa_audit_event']));
            for ($pass=0; $pass<20 && $pending; $pass++) {
                $next=[];
                foreach ($pending as $table) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) throw new RuntimeException('Unexpected schema identifier.');
                    if (!$execute("SELECT 1 FROM `$table` WHERE tenant_id=? LIMIT 1",[$tenant['id']])->fetchColumn()) continue;
                    try { $execute("DELETE FROM `$table` WHERE tenant_id=?",[$tenant['id']]); }
                    catch (PDOException $error) { if ($error->getCode() !== '23000') throw $error; $next[]=$table; }
                }
                $pending=$next;
            }
            if ($pending) throw new RuntimeException('Fixture cleanup dependencies could not be resolved.');
            if ($execute('SELECT id FROM nexa_plan_definition WHERE id=? AND plan_key=?',[$tenant['id'],$tenant['slug']])->fetchColumn()) {
                if ($execute('SELECT COUNT(*) FROM nexa_tenant_subscription WHERE plan_id=?',[$tenant['id']])->fetchColumn()) throw new RuntimeException('Fixture plan is still referenced; refusing cleanup.');
                $execute('DELETE FROM nexa_plan_service WHERE plan_id=?',[$tenant['id']]);
                $execute('DELETE FROM nexa_plan_definition WHERE id=? AND plan_key=?',[$tenant['id'],$tenant['slug']]);
            }
            if ($execute('SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id=?',[$tenant['id']])->fetchColumn()) {
                $execute("UPDATE nexa_tenant SET status='suspended' WHERE id=? AND slug=?",[$tenant['id'],$tenant['slug']]);
            } else $execute('DELETE FROM nexa_tenant WHERE id=? AND slug=?',[$tenant['id'],$tenant['slug']]);
        }
    });
    echo json_encode(['cleaned'=>count($tenants)]); exit;
}

$tenant = $tenants[$input['index'] ?? 0];
$context = new TenantContext($tenant['id'],$tenant['slug'],'automation-acceptance','Automation acceptance fixture',$tenant['serviceId']);
$result = $contexts->runWith($context, function () use ($input,$command,$tenant,$tenants,$factory,$em,$pdo,$execute,$atomic,$container): mixed {
    $worker = $factory->create(WorkflowService::class);
    $webhooks = $factory->create(WorkflowWebhookService::class);
    $user = $em->getEntityById('User',$tenant['userId']);
    $admin = $factory->createWith(WorkflowService::class,['user'=>$user]);
    if (!empty($input['barrier']) && !in_array($command,['hold-quota','queue-prefetch-process'],true)) { echo "READY\n"; flush(); if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Worker barrier was not released.'); }
    switch ($command) {
        case 'queue-setup':
            $queueName='fair-'.substr(hash('sha256',$tenants[0]['id']),0,20);
            $scheduled=null;
            if (!empty($input['scheduled'])) {
                $scheduled=$em->createEntity('ScheduledJob',['name'=>'Queue acceptance schedule','job'=>'RecalculateScoringModels','scheduling'=>'* * * * *','status'=>'Inactive']);
            }
            $ids=[];
            for ($i=0;$i<max(1,min(1000,(int)($input['count']??1)));$i++) {
                $job=$em->createEntity('Job',['name'=>'Queue acceptance job','queue'=>$queueName,'status'=>'Pending','executeTime'=>gmdate('Y-m-d H:i:s',time()-10),
                    'className'=>\Espo\Custom\Jobs\RecalculateScoringModels::class,'scheduledJobId'=>$scheduled?->getId()]);
                $ids[]=$job->getId();
            }
            return ['ids'=>$ids];
        case 'queue-select':
        case 'queue-process':
        case 'queue-prefetch-process':
            $queueName='fair-'.substr(hash('sha256',$tenants[0]['id']),0,20);
            $params=\Espo\Core\Job\QueueProcessor\Params::create()->withQueue($queueName)->withLimit(max(1,min(10,(int)($input['limit']??1))));
            $queue=$factory->create(\Espo\Core\Job\QueueUtil::class);
            if ($command==='queue-prefetch-process') {
                require_once __DIR__.'/QueueDispatchBarrier.php';
                $queue=$factory->create(QueueDispatchBarrier::class);
            }
            return $container->getByClass(\Espo\Core\Tenant\PlatformExecutionGateway::class)->run('Disposable queue acceptance',function () use ($command,$params,$queue,$factory,$execute,$queueName) {
                if ($command==='queue-select') {
                    $rows=[];
                    foreach ($queue->getPendingJobList($params) as $job) {$rows[]=['id'=>$job->getId(),'tenantId'=>$job->get('tenantId'),'serviceId'=>$job->get('serviceId')];}
                    return $rows;
                }
                $factory->createWith(\Espo\Core\Job\QueueProcessor::class,['queueUtil'=>$queue])->process($params);
                return $execute('SELECT tenant_id,status,COUNT(*) AS total FROM job WHERE queue=? AND deleted=0 GROUP BY tenant_id,status ORDER BY tenant_id,status',[$queueName])->fetchAll(PDO::FETCH_ASSOC);
            });
        case 'queue-cancel':
            $queueName='fair-'.substr(hash('sha256',$tenants[0]['id']),0,20);
            $job=$em->getEntityById('Job',(string)$input['jobId']);
            if (!$job || $job->get('queue')!==$queueName || $job->get('tenantId')!==$tenant['id'] || $job->get('serviceId')!==$tenant['serviceId']) { throw new RuntimeException('Only owned fixture jobs may be cancelled.'); }
            $em->removeEntity($job);
            return ['cancelled'=>true];
        case 'queue-inspect':
            $queueName='fair-'.substr(hash('sha256',$tenants[0]['id']),0,20);
            return $execute('SELECT id,tenant_id,service_id,status,deleted FROM job WHERE queue=? ORDER BY number',[$queueName])->fetchAll(PDO::FETCH_ASSOC);
        case 'queue-selection-failure':
            $queueName='fair-'.substr(hash('sha256',$tenants[0]['id']),0,20);
            $scope=hash('sha256',json_encode([$queueName,null],JSON_THROW_ON_ERROR));
            $before=$execute('SELECT last_tenant_id FROM nexa_job_dispatch_rotation WHERE scope_key=?',[$scope])->fetchColumn();
            try {
                $container->getByClass(\Espo\Core\Tenant\PlatformExecutionGateway::class)->run('Disposable failed selection acceptance',
                    fn ()=>$factory->create(\Espo\Core\Job\TenantFairQueue::class)->select(
                        \Espo\Core\Job\QueueProcessor\Params::create()->withQueue($queueName)->withLimit(1),
                        static function () { throw new RuntimeException('Expected queue selection failure.'); },
                    ));
                throw new LogicException('Selection did not fail.');
            } catch (RuntimeException $error) {
                if ($error->getMessage()!=='Expected queue selection failure.') { throw $error; }
            }
            $after=$execute('SELECT last_tenant_id FROM nexa_job_dispatch_rotation WHERE scope_key=?',[$scope])->fetchColumn();
            return ['cursorUnchanged'=>$before===$after,'transactionReleased'=>!$pdo->inTransaction()];
        case 'website-personalization-setup':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $cookies=$factory->createWith(\Espo\Custom\Tools\Consent\CookieConsentService::class,['user'=>$user,'acl'=>$acl]);
            $banner=$cookies->getWorkspace()['banner'];$banner['isPublished']=true;$cookies->save((object)$banner);
            $contact=$em->getEntityById('Contact',$tenant['contactId']);$contact->set('leadStatus','New');$em->saveEntity($contact);
            $pages=$factory->createWith(\Espo\Custom\Tools\LandingPage\LandingPageService::class,['user'=>$user,'acl'=>$acl]);
            $page=$pages->save((object)['name'=>'HTTPS customer demo','slug'=>'https-'.$tenant['slug'],'locale'=>'en','blocks'=>[(object)[
                'id'=>'hero','type'=>'hero','heading'=>'Public offer '.$tenant['slug'],'text'=>'Unidentified visitor',
                'personalization'=>(object)['field'=>'customer:leadStatus','value'=>'New','heading'=>'Verified offer '.$tenant['slug'],'text'=>'<img src=x onerror=alert(1)>'],
            ]]]);
            $pages->publish($page['id']);
            $api=$em->createEntity('User',['userName'=>$tenant['slug'].'-website','type'=>'api','isActive'=>true]);
            $key=bin2hex(random_bytes(20));$execute("UPDATE user SET api_key=?,auth_method='ApiKey' WHERE id=? AND tenant_id=?",[$key,$api->getId(),$tenant['id']]);
            $role=$em->createEntity('Role',['name'=>'Customer demo read only','data'=>['Contact'=>['read'=>'all','edit'=>'no','create'=>'no','delete'=>'no']]]);
            $em->getRDBRepository('User')->getRelation($api,'roles')->relate($role);
            $password=bin2hex(random_bytes(18));
            return ['apiKey'=>$key,'apiUserId'=>$api->getId(),'contactId'=>$contact->getId(),'pageId'=>$page['id'],'publicKey'=>$banner['publicKey'],
                'email'=>$tenant['slug'].'-customer@example.test','password'=>$password,'passwordHash'=>password_hash($password,PASSWORD_BCRYPT)];
        case 'website-personalization-expire':
            $execute('UPDATE nexa_personalization_identity_proof SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']]);
            return ['expired'=>true];
        case 'phase7-capacity':
            $checks=require __DIR__.'/Phase7CapacityChecks.php';
            return $checks($em,$factory,$container,$user,$tenant);
        case 'scoring-concurrency-setup':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $scoring=$factory->createWith(\Espo\Custom\Tools\Scoring\ScoringService::class,['user'=>$user,'acl'=>$acl]);
            $contactCount=$input['contacts']??20;if(!is_int($contactCount)||$contactCount<1||$contactCount>1000)throw new RuntimeException('Invalid fixture contact count.');
            for($i=0;$i<$contactCount;$i++)$em->createEntity('Contact',['firstName'=>'Worker fixture','lastName'=>(string)$i,'title'=>'Director']);
            $model=$scoring->save((object)['name'=>'Independent worker acceptance','entityType'=>'Contact','definition'=>(object)['rules'=>[(object)['id'=>'fit','field'=>'title','operator'=>'has_value','points'=>10]]]]);
            $scoring->control($model['id'],(object)['action'=>'publish']);
            $scoring->control($model['id'],(object)['action'=>'schedule','enabled'=>true,'intervalHours'=>24]);
            $execute('UPDATE nexa_scoring_model SET next_evaluation_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=? AND id=?',[$tenant['id'],$tenant['serviceId'],$model['id']]);
            return ['modelId'=>$model['id']];
        case 'scoring-tick':
            return ['processed'=>$factory->create(\Espo\Custom\Tools\Scoring\ScoringService::class)->processDue()];
        case 'scoring-native-job':
            $before=(int)$execute('SELECT COUNT(*) FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn();
            $job=$em->createEntity('Job',['name'=>'Phase 7 fairness fixture','className'=>\Espo\Custom\Jobs\RecalculateScoringModels::class,'status'=>'Pending','executeTime'=>gmdate('Y-m-d H:i:s')]);
            $job=$em->getEntityById('Job',$job->getId());
            $started=microtime(true);$factory->create(\Espo\Core\Job\JobRunner::class)->runThrowingException($job);
            $after=(int)$execute('SELECT COUNT(*) FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn();
            return ['processed'=>$after-$before,'seconds'=>round(microtime(true)-$started,3),'status'=>$em->getEntityById('Job',$job->getId())->get('status')];
        case 'abm-concurrency-setup':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $abm=$factory->createWith(\Espo\Custom\Tools\Abm\AbmService::class,['user'=>$user,'acl'=>$acl]);
            $account=$em->createEntity('Account',['name'=>'Worker ABM company']);
            $contact=$em->getEntityById('Contact',$tenant['contactId']);$contact->set('accountId',$account->getId());$em->saveEntity($contact);
            $abm->target($account->getId(),(object)['isTargetAccount'=>true,'targetAccountTier'=>'Tier1']);
            $list=$abm->save((object)['name'=>'Worker ABM list','type'=>'static','accountIds'=>[$account->getId()]]);
            $execute('UPDATE nexa_abm_list SET next_refresh_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=? AND target_list_id=?',[$tenant['id'],$tenant['serviceId'],$list['id']]);
            return ['listId'=>$list['id'],'accountId'=>$account->getId()];
        case 'abm-tick':
            return ['processed'=>$factory->create(\Espo\Custom\Tools\Abm\AbmService::class)->processDue()];
        case 'abm-competing-edit':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $abm=$factory->createWith(\Espo\Custom\Tools\Abm\AbmService::class,['user'=>$user,'acl'=>$acl]);
            try {
                $abm->save((object)['id'=>$input['listId'],'expectedVersion'=>1,'name'=>'Concurrent edit '.$input['label'],'type'=>'static','accountIds'=>[$input['accountId']]]);
                return ['saved'=>true];
            } catch (\Espo\Core\Exceptions\BadRequest $error) {
                if($error->getMessage()!=='This account list changed. Reload it before saving.')throw $error;
                return ['saved'=>false,'conflict'=>true];
            }
        case 'abm-inspect':
            return ['lists'=>$execute('SELECT target_list_id,version_number,account_count,last_error_code FROM nexa_abm_list WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
                'members'=>$execute('SELECT contact_id,target_list_id,COUNT(*) copies FROM contact_target_list WHERE tenant_id=? AND service_id=? AND deleted=0 GROUP BY contact_id,target_list_id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC)];
        case 'scoring-wake':
            $execute("UPDATE nexa_scoring_model SET next_evaluation_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=? AND status='published'",[$tenant['id'],$tenant['serviceId']]);
            return ['woken'=>true];
        case 'scoring-archive':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $scoring=$factory->createWith(\Espo\Custom\Tools\Scoring\ScoringService::class,['user'=>$user,'acl'=>$acl]);
            $scoring->control($input['modelId'],(object)['action'=>'archive']);
            return ['archived'=>true];
        case 'scoring-inspect':
            return ['evaluations'=>$execute('SELECT model_id,record_id,COUNT(*) copies FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? GROUP BY model_id,record_id ORDER BY record_id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
                'models'=>$execute('SELECT id,status,automatic_enabled,scan_cursor,scan_key,last_error_code FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? ORDER BY id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC)];
        case 'scoring-quota-setup':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $scoring=$factory->createWith(\Espo\Custom\Tools\Scoring\ScoringService::class,['user'=>$user,'acl'=>$acl]);
            $workspace=$scoring->workspace();$limit=$workspace['publishedLimit'];
            if(!in_array($limit,[5,50],true))throw new RuntimeException('An entitled fixture plan is required.');
            $make=fn($name)=>$scoring->save((object)['name'=>$name,'entityType'=>'Contact','definition'=>(object)['rules'=>[(object)['id'=>'fit','field'=>'title','operator'=>'has_value','points'=>10]]]]);
            $active=count(array_filter($workspace['models'],fn($model)=>$model['status']==='published'));
            for($i=$active;$i<$limit-1;$i++){$model=$make('Quota fixture '.$i);$scoring->control($model['id'],(object)['action'=>'publish']);}
            return ['limit'=>$limit,'first'=>$make('Quota candidate one')['id'],'second'=>$make('Quota candidate two')['id']];
        case 'scoring-competing-publish':
            $acl=$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $scoring=$factory->createWith(\Espo\Custom\Tools\Scoring\ScoringService::class,['user'=>$user,'acl'=>$acl]);
            try{$scoring->control($input['modelId'],(object)['action'=>'publish']);return ['published'=>true];}
            catch(\Espo\Core\Exceptions\BadRequest $error){if($error->getMessage()!=='The plan limit for active scoring models has been reached.')throw $error;return ['published'=>false,'quotaRejected'=>true];}
        case 'abm-company':
            $company=$factory->create(\Espo\Core\Record\ServiceFactory::class)->createForUser('Account',$user)->create((object)['name'=>'ABM acceptance company '.$tenant['slug']],\Espo\Core\Record\CreateParams::create());
            return ['id'=>$company->getId()];
        case 'programmable-setup':
            $capture = !empty($input['capture']);
            $transactional = !empty($input['transactional']);
            $postal = null;
            if ($capture) {
                if (getenv('NEXA_POSTAL_CAPTURE_ACCEPTANCE')!=='1') throw new RuntimeException('Explicit local Postal capture opt-in is required.');
                $postal = json_decode(file_get_contents(dirname(__DIR__,2).'/.local/postal/config/local-credentials.json'),true,32,JSON_THROW_ON_ERROR);
                if ($postal['apiBaseUrl']!=='https://localhost:8443' || $postal['sender']!=='newsletter@nexa.local.test') throw new RuntimeException('Only the pre-existing isolated local Postal capture is allowed.');
            }
            $acl = $container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $service = static fn (string $class) => $factory->createWith($class, ['user'=>$user,'acl'=>$acl]);
            $execute("INSERT INTO nexa_tenant_service (tenant_id,service_id,status,hard_limit_override) SELECT ?,id,'active',1000 FROM nexa_service_definition WHERE service_key='marketing.contacts' AND status='active'",[$tenant['id']]);
            $factory->create(\Espo\Core\Record\ServiceFactory::class)->createForUser('Contact',$user)->update($tenant['contactId'],(object) ['emailAddress'=>$tenant['slug'].'-recipient@example.test','marketingStatus'=>'Marketing','addressCountry'=>'Acceptance Test'],\Espo\Core\Record\UpdateParams::create());
            $consent = $service(\Espo\Custom\Tools\Consent\ConsentService::class);
            $purposeKey = $transactional ? 'customer_service' : 'marketing_communications';
            $purpose = array_values(array_filter($consent->getWorkspace()['purposes'],static fn ($item) => $item['purposeKey']===$purposeKey))[0];
            $consent->recordDecision((object) ['contactId'=>$tenant['contactId'],'purposeId'=>$purpose['id'],'channel'=>'email','status'=>$transactional?'not_required':'granted','source'=>'manual','legalBasis'=>$transactional?'PerformanceOfContract':'FreelyGivenConsent','evidenceNote'=>'Synthetic programmable HTTP acceptance fixture.']);
            $segment = $service(\Espo\Custom\Tools\Segment\SegmentWorkspaceService::class)->save((object) ['name'=>'Programmable acceptance audience','type'=>'dynamic','matchMode'=>'all','rules'=>[(object) ['field'=>'country','operator'=>'equals','value'=>'Acceptance Test']]]);
            $campaignService = $service(\Espo\Custom\Tools\Campaign\CampaignWorkspaceService::class);
            $campaign = $campaignService->save((object) ['name'=>'Programmable acceptance campaign','type'=>'Email','channel'=>'email','purposeId'=>$purpose['id'],'audienceIds'=>[$segment['id']],'exclusionIds'=>[],'enrollmentMode'=>'snapshot']);
            $campaignService->enroll($campaign['id']);
            if ($transactional) {
                $plan = $execute('SELECT plan_id FROM nexa_tenant_subscription WHERE tenant_id=?',[$tenant['id']])->fetchColumn();
                $execute("INSERT INTO nexa_plan_definition (id,plan_key,display_name,status) VALUES (?,?,'Transactional acceptance fixture','active')",[$tenant['id'],$tenant['slug']]);
                $execute('INSERT INTO nexa_plan_service (plan_id,service_id,is_enabled,soft_limit,hard_limit,configuration_json) SELECT ?,service_id,is_enabled,soft_limit,hard_limit,configuration_json FROM nexa_plan_service WHERE plan_id=?',[$tenant['id'],$plan]);
                $execute("INSERT INTO nexa_plan_service (plan_id,service_id,is_enabled,hard_limit) SELECT ?,id,1,100 FROM nexa_service_definition WHERE service_key='email.transactional'",[$tenant['id']]);
                $execute("INSERT INTO nexa_tenant_service (tenant_id,service_id,status,hard_limit_override) SELECT ?,id,'active',100 FROM nexa_service_definition WHERE service_key='email.transactional'",[$tenant['id']]);
                $execute('UPDATE nexa_tenant_subscription SET plan_id=? WHERE tenant_id=?',[$tenant['id'],$tenant['id']]);
                $execute("UPDATE contact SET marketing_status='Non-Marketing' WHERE id=? AND tenant_id=? AND service_id=?",[$tenant['contactId'],$tenant['id'],$tenant['serviceId']]);
                $execute('DELETE FROM nexa_campaign_enrollment WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']]);
            }
            $delivery = $service(\Espo\Custom\Tools\MarketingEmail\MarketingDeliveryService::class);
            $delivery->saveProvider((object) ['apiBaseUrl'=>$postal['apiBaseUrl'] ?? 'https://postal.example.test','serverName'=>'Local acceptance only','apiKey'=>$postal['apiKey'] ?? bin2hex(random_bytes(24)),'monthlySendLimit'=>100]);
            $domainName = $capture ? 'nexa.local.test' : 'example.test';
            $settings = $delivery->saveDomain((object) ['domainName'=>$domainName]);
            $domainId = array_values(array_filter($settings['domains'],static fn ($domain) => $domain['domainName']===$domainName))[0]['id'];
            // Synthetic readiness supports queue acceptance only, never DNS/delivery proof.
            $execute("UPDATE nexa_marketing_sending_domain SET ownership_status='verified',spf_status='verified',dkim_status='verified',dmarc_status='verified',return_path_status='verified',verified_at=NOW(6) WHERE id=? AND tenant_id=?",[$domainId,$tenant['id']]);
            $draft = $service(\Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService::class)->save((object) ['name'=>'Programmable HTTP source','deliveryMode'=>$transactional?'transactional':'campaign','campaignId'=>$transactional?'':$campaign['id'],'purposeId'=>$purpose['id'],'subject'=>'Acceptance [[nexa:reference:40]]','bodyHtml'=>'<p>[[nexa:message:255]] {Contact.firstName}</p>','fromAddress'=>$postal['sender'] ?? 'news@example.test','fromName'=>'Synthetic acceptance','startAt'=>gmdate('Y-m-d H:i:s',time()+3600)]);
            $key = bin2hex(random_bytes(20));
            $apiUser = $em->createEntity('User',['userName'=>$tenant['slug'].'-api','emailAddress'=>$tenant['slug'].'-api@example.test','firstName'=>'Acceptance','lastName'=>'Integration','type'=>'api','isActive'=>true]);
            $execute("UPDATE user SET api_key=?,auth_method='ApiKey' WHERE id=? AND tenant_id=?",[$key,$apiUser->getId(),$tenant['id']]);
            $permissions = new stdClass();
            foreach (['MassEmail','EmailTemplate','Contact','Attachment','Campaign'] as $scope) $permissions->{$scope}=(object) ['read'=>'all','create'=>'no','edit'=>'no','delete'=>'no','stream'=>'no'];
            $role = $em->createEntity('Role',['name'=>'Read-only acceptance integration','data'=>$permissions]);
            $em->getRDBRepository('User')->getRelation($apiUser,'roles')->relate($role);
            $callback = $delivery->rotateCallbackToken();
            return ['sourceId'=>$draft['id'],'campaignId'=>$campaign['id'],'purposeId'=>$purpose['id'],'version'=>1,'apiUserId'=>$apiUser->getId(),'apiKey'=>$key,'callbackToken'=>$callback['callbackToken']];
        case 'experiment-setup':
            if (getenv('NEXA_PHASE6_CLOSURE_ACCEPTANCE')!=='1') throw new RuntimeException('Combined email acceptance opt-in is required.');
            $acl = $container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user);
            $consent = $factory->createWith(\Espo\Custom\Tools\Consent\ConsentService::class,['user'=>$user,'acl'=>$acl]);
            $campaign = $execute("SELECT campaign_id,purpose_id,audience_ids_json FROM nexa_campaign_profile WHERE tenant_id=? AND service_id=? AND workflow_status='active'",[$tenant['id'],$tenant['serviceId']])->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) throw new RuntimeException('An owned active synthetic campaign is required.');
            $ids = [$tenant['contactId']];
            $recipients = [$tenant['contactId']=>$tenant['slug'].'-recipient@example.test'];
            $contactRecords = $factory->create(\Espo\Core\Record\ServiceFactory::class)->createForUser('Contact',$user);
            for ($i=1;$i<=7;$i++) {
                $contact = $contactRecords->create((object) ['firstName'=>'Acceptance','lastName'=>'Experiment '.$i,'emailAddress'=>$tenant['slug'].'-experiment-'.$i.'@example.test','marketingStatus'=>'Marketing','addressCountry'=>'Acceptance Test'],\Espo\Core\Record\CreateParams::create());
                $ids[] = $contact->getId();
                $recipients[$contact->getId()] = $tenant['slug'].'-experiment-'.$i.'@example.test';
                $consent->recordDecision((object) ['contactId'=>$contact->getId(),'purposeId'=>$campaign['purpose_id'],'channel'=>'email','status'=>'granted','source'=>'manual','legalBasis'=>'FreelyGivenConsent','evidenceNote'=>'Synthetic local A/B acceptance audience.']);
            }
            $segments = $factory->createWith(\Espo\Custom\Tools\Segment\SegmentWorkspaceService::class,['user'=>$user,'acl'=>$acl]);
            foreach (json_decode($campaign['audience_ids_json'],true,32,JSON_THROW_ON_ERROR) as $segmentId) $segments->recalculate($segmentId);
            $enrollment = $factory->createWith(\Espo\Custom\Tools\Campaign\CampaignWorkspaceService::class,['user'=>$user,'acl'=>$acl])->enroll($campaign['campaign_id']);
            if ((int) $enrollment['eligibleCount'] !== 8) throw new RuntimeException('All eight synthetic Contacts must be natively eligible before experiment acceptance.');
            return ['contactIds'=>$ids,'recipients'=>$recipients];
        case 'experiment-inspect':
            if (getenv('NEXA_PHASE6_CLOSURE_ACCEPTANCE')!=='1') throw new RuntimeException('Combined email acceptance opt-in is required.');
            return $execute('SELECT contact_id,cohort,variant_key,status,queue_item_id,send_profile_id FROM nexa_email_experiment_assignment WHERE tenant_id=? AND service_id=? AND experiment_id=? ORDER BY contact_id',[$tenant['id'],$tenant['serviceId'],$input['experimentId']])->fetchAll(PDO::FETCH_ASSOC);
        case 'experiment-expire-window':
            if (getenv('NEXA_PHASE6_CLOSURE_ACCEPTANCE')!=='1') throw new RuntimeException('Combined email acceptance opt-in is required.');
            $execute("UPDATE nexa_email_experiment SET decision_due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=? AND id=? AND status='awaiting_winner'",[$tenant['id'],$tenant['serviceId'],$input['experimentId']]);
            return ['expired'=>true];
        case 'experiment-worker':
            if (getenv('NEXA_PHASE6_CLOSURE_ACCEPTANCE')!=='1') throw new RuntimeException('Combined email acceptance opt-in is required.');
            $factory->create(\Espo\Custom\Tools\EmailExperiment\EmailExperimentService::class)->runScheduled();
            return ['processed'=>true];
        case 'experiment-capture-worker':
            if (getenv('NEXA_PHASE6_CLOSURE_ACCEPTANCE')!=='1' || getenv('NEXA_POSTAL_CAPTURE_ACCEPTANCE')!=='1') throw new RuntimeException('Explicit local experiment capture opt-in is required.');
            if ($execute('SELECT api_base_url FROM nexa_marketing_delivery_provider WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn()!=='https://localhost:8443') throw new RuntimeException('Only loopback Postal capture is allowed.');
            $targets=$execute("SELECT q.target_type,q.is_test,q.target_id,ea.name FROM email_queue_item q INNER JOIN entity_email_address eea ON eea.entity_id=q.target_id AND eea.entity_type='Contact' AND eea.`primary`=1 AND eea.deleted=0 AND eea.tenant_id=q.tenant_id AND eea.service_id=q.service_id INNER JOIN email_address ea ON ea.id=eea.email_address_id AND ea.tenant_id=q.tenant_id AND ea.service_id=q.service_id WHERE q.tenant_id=? AND q.service_id=?",[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC);
            $count=(int)$execute('SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn();
            if (count($targets)!==$count) throw new RuntimeException('Every experiment queue needs a scoped synthetic recipient.');
            foreach($targets as $target) if($target['target_type']!=='Contact' || $target['is_test'] || !preg_match('/^'.preg_quote($tenant['slug'],'/').'-(?:recipient|experiment-[1-7])@example\.test$/D',$target['name'])) throw new RuntimeException('Experiment capture is limited to exact fixture recipients.');
            $container->getByClass(\Espo\Core\Utils\Config::class)->set('nexaPostalLocalCaFile',dirname(__DIR__,2).'/.local/postal/root.crt');
            $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingDispatchService::class)->runScheduled();
            return ['processed'=>true];
        case 'programmable-inspect':
            return [
                'requests'=>$execute('SELECT request_hash,profile_id,contact_id,source_profile_id,source_version,parameter_fingerprint FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? ORDER BY request_hash',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
                'queues'=>$execute('SELECT id,status,target_id FROM email_queue_item WHERE tenant_id=? AND service_id=? ORDER BY id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
                'copies'=>$execute('SELECT t.subject,t.body FROM nexa_marketing_send_request r INNER JOIN nexa_marketing_email_profile p ON p.id=r.profile_id AND p.tenant_id=r.tenant_id AND p.service_id=r.service_id INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id INNER JOIN email_template t ON t.id=m.email_template_id AND t.tenant_id=m.tenant_id AND t.service_id=m.service_id WHERE r.tenant_id=? AND r.service_id=? ORDER BY r.request_hash',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
                'submissions'=>(int)$execute('SELECT COUNT(*) FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn(),
                'messages'=>$execute('SELECT email_queue_item_id,status,provider_message_id,attempt_count,usage_class,reserved_usage_month FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=? ORDER BY email_queue_item_id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
                'usage'=>$execute("SELECT accepted_count,transactional_accepted_count FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')",[$tenant['id'],$tenant['serviceId']])->fetch(PDO::FETCH_ASSOC) ?: null,
                'inspections'=>(int)$execute('SELECT COALESCE(SUM(inspection_attempt_count),0) FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn(),
            ];
        case 'programmable-hold-submission':
            if (getenv('NEXA_POSTAL_CAPTURE_ACCEPTANCE')!=='1') throw new RuntimeException('Local capture opt-in is required.');
            $message = $execute("SELECT * FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=? AND status='submitted' AND provider_record_id IS NOT NULL ORDER BY id LIMIT 1",[$tenant['id'],$tenant['serviceId']])->fetch(PDO::FETCH_ASSOC);
            if (!$message) throw new RuntimeException('A real captured submission is required.');
            $execute("UPDATE email_queue_item SET status='Sending' WHERE id=? AND tenant_id=? AND service_id=?",[$message['email_queue_item_id'],$tenant['id'],$tenant['serviceId']]);
            $execute("UPDATE nexa_marketing_delivery_message SET last_error='Postal acceptance is uncertain. Synthetic lost queue acknowledgement.',last_inspected_at=NULL WHERE id=? AND tenant_id=? AND service_id=?",[$message['id'],$tenant['id'],$tenant['serviceId']]);
            $execute("UPDATE nexa_marketing_email_profile SET lifecycle_status='paused' WHERE id=? AND tenant_id=? AND service_id=?",[$message['profile_id'],$tenant['id'],$tenant['serviceId']]);
            return ['held'=>true];
        case 'programmable-revoke':
            $execute("UPDATE nexa_consent_state SET status='withdrawn' WHERE tenant_id=? AND service_id=? AND channel='email'",[$tenant['id'],$tenant['serviceId']]);
            return ['revoked'=>true];
        case 'programmable-worker':
            if ($execute("SELECT 1 FROM nexa_consent_state WHERE tenant_id=? AND service_id=? AND channel='email' AND status IN ('granted','not_required') LIMIT 1",[$tenant['id'],$tenant['serviceId']])->fetchColumn()) throw new RuntimeException('Revoke every synthetic recipient before running a no-network email worker.');
            if ($execute('SELECT api_base_url FROM nexa_marketing_delivery_provider WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn()!=='https://postal.example.test') throw new RuntimeException('Only the non-deliverable synthetic provider fixture is allowed.');
            $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingDispatchService::class)->runScheduled();
            return ['processed'=>true];
        case 'programmable-capture-worker':
            if (getenv('NEXA_POSTAL_CAPTURE_ACCEPTANCE')!=='1') throw new RuntimeException('Explicit local Postal capture opt-in is required.');
            if ($execute('SELECT api_base_url FROM nexa_marketing_delivery_provider WHERE tenant_id=? AND service_id=?',[$tenant['id'],$tenant['serviceId']])->fetchColumn()!=='https://localhost:8443') throw new RuntimeException('Only loopback Postal capture is allowed.');
            $recipient = $em->getEntityById('Contact',$tenant['contactId']);
            if (!$recipient || $recipient->get('emailAddress')!==$tenant['slug'].'-recipient@example.test') throw new RuntimeException('Only the exact synthetic fixture recipient is allowed.');
            if ($execute("SELECT 1 FROM email_queue_item WHERE tenant_id=? AND service_id=? AND (target_type<>'Contact' OR target_id<>? OR is_test=1) LIMIT 1",[$tenant['id'],$tenant['serviceId'],$tenant['contactId']])->fetchColumn()) throw new RuntimeException('Capture queues must target only the fixture Contact.');
            $container->getByClass(\Espo\Core\Utils\Config::class)->set('nexaPostalLocalCaFile',dirname(__DIR__,2).'/.local/postal/root.crt');
            $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingDispatchService::class)->runScheduled();
            return ['processed'=>true];
        case 'hold-quota':
            return $atomic(function () use ($factory): array {
                $factory->create(\Espo\Custom\Tools\Automation\WorkflowQuota::class)->limits(true);
                echo "READY\n"; flush();
                if(trim((string)fgets(STDIN))!=='GO') throw new RuntimeException('Quota lock barrier was not released.');
                return ['released'=>true];
            });
        case 'tick':
            $count=0;
            for ($i=0;$i<($input['ticks'] ?? 1);$i++) $count += $worker->processDue();
            return ['processed'=>$count];
        case 'closure-wake':
            if (getenv('NEXA_PHASE6_CLOSURE_ACCEPTANCE')!=='1') throw new RuntimeException('Closure acceptance opt-in is required.');
            $execute("UPDATE nexa_workflow_run SET due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=? AND workflow_id=? AND status='waiting'",[$tenant['id'],$tenant['serviceId'],$input['workflowId']]);
            return ['woken'=>true];
        case 'approved-delivery':
            $approved = getenv('NEXA_AUTOMATION_WEBHOOK_URL');
            if (!$approved || !hash_equals($approved,(string)$input['url'])) throw new RuntimeException('An explicitly approved test endpoint is required.');
            $hook=$em->getEntityById('Webhook',$tenant['webhookId']);
            if (!$hook || $hook->get('url')!==$approved) throw new RuntimeException('The fixture webhook must match the approved endpoint.');
            return ['processed'=>$webhooks->process()];
        case 'configure-approved-hook':
            $approved = getenv('NEXA_AUTOMATION_WEBHOOK_URL');
            if (!$approved || !hash_equals($approved,(string)$input['url'])) throw new RuntimeException('An explicitly approved test endpoint is required.');
            $factory->create(\Espo\Custom\Tools\Automation\WorkflowWebhookTransport::class)->validateUrl($approved);
            $execute('UPDATE webhook SET url=? WHERE id=? AND tenant_id=?',[$approved,$tenant['webhookId'],$tenant['id']]);
            return ['configured'=>true];
        case 'cancel': return $admin->cancel($input['workflowId'],$input['runId']);
        case 'claim':
            return $atomic(function () use ($webhooks,$input): array {
                $claim=$webhooks->claim($input['effectId']);
                // Never return signing material or submit an unapproved request.
                return $claim ? ['id'=>$claim['id'],'token'=>$claim['token']] : ['claimed'=>false];
            });
        case 'finalize':
            $atomic(fn()=>$webhooks->finalize($input['effectId'],$input['token'],['outcome'=>'success','status'=>204,'code'=>null]));
            return ['finalized'=>true];
        case 'inspect':
            return [
                'runs'=>$execute('SELECT id,status,node_id FROM nexa_workflow_run WHERE tenant_id=? AND service_id=? AND workflow_id=? ORDER BY id',[$tenant['id'],$tenant['serviceId'],$input['workflowId']])->fetchAll(PDO::FETCH_ASSOC),
                'events'=>$execute('SELECT run_id,event_type,node_id,actor_user_id,payload_json FROM nexa_workflow_event WHERE tenant_id=? AND service_id=? AND workflow_id=? ORDER BY created_at,id',[$tenant['id'],$tenant['serviceId'],$input['workflowId']])->fetchAll(PDO::FETCH_ASSOC),
                'effects'=>$execute('SELECT id,status,attempts,run_id,last_error_code FROM nexa_workflow_webhook_effect WHERE tenant_id=? AND service_id=? AND workflow_id=?',[$tenant['id'],$tenant['serviceId'],$input['workflowId']])->fetchAll(PDO::FETCH_ASSOC),
                'score'=>(int)$execute('SELECT lead_score FROM contact WHERE id=? AND tenant_id=?',[$tenant['contactId'],$tenant['id']])->fetchColumn(),
            ];
        case 'mutate':
            switch ($input['change']) {
                case 'user-inactive': $execute('UPDATE user SET is_active=0 WHERE id=? AND tenant_id=?',[$tenant['userId'],$tenant['id']]); break;
                case 'user-active': $execute("UPDATE user SET is_active=1,type='admin' WHERE id=? AND tenant_id=?",[$tenant['userId'],$tenant['id']]); break;
                case 'user-regular': $execute("UPDATE user SET type='regular' WHERE id=? AND tenant_id=?",[$tenant['userId'],$tenant['id']]); break;
                case 'contact-deleted': $execute('UPDATE contact SET deleted=1 WHERE id=? AND tenant_id=?',[$tenant['contactId'],$tenant['id']]); break;
                case 'contact-active': $execute('UPDATE contact SET deleted=0 WHERE id=? AND tenant_id=?',[$tenant['contactId'],$tenant['id']]); break;
                case 'contact-other-service': $execute("UPDATE contact SET service_id=(SELECT id FROM nexa_service_definition WHERE id<>? AND status='active' ORDER BY id LIMIT 1) WHERE id=? AND tenant_id=?",[$tenant['serviceId'],$tenant['contactId'],$tenant['id']]); break;
                case 'contact-own-service': $execute('UPDATE contact SET service_id=? WHERE id=? AND tenant_id=?',[$tenant['serviceId'],$tenant['contactId'],$tenant['id']]); break;
                case 'hook-inactive': $execute('UPDATE webhook SET is_active=0 WHERE id=? AND tenant_id=?',[$tenant['webhookId'],$tenant['id']]); break;
                case 'hook-active': $execute('UPDATE webhook SET is_active=1 WHERE id=? AND tenant_id=?',[$tenant['webhookId'],$tenant['id']]); break;
                case 'concurrency-one': $execute("UPDATE nexa_tenant_service g INNER JOIN nexa_service_definition d ON d.id=g.service_id AND d.service_key='automation' SET g.configuration_json=JSON_SET(COALESCE(g.configuration_json,'{}'),'$.maxConcurrentWebhooks',1) WHERE g.tenant_id=?",[$tenant['id']]); break;
                case 'lease-expired': $execute("UPDATE nexa_workflow_webhook_effect SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=? AND tenant_id=? AND status='sending'",[$input['effectId'],$tenant['id']]); break;
                default: throw new RuntimeException('Unsupported fixture mutation.');
            }
            return ['changed'=>true];
        default: throw new RuntimeException('Unsupported fixture command.');
    }
});
echo json_encode($result,JSON_THROW_ON_ERROR);
