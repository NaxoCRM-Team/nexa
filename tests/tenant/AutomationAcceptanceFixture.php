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
            if ($execute('SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id=?',[$tenant['id']])->fetchColumn()) {
                $execute("UPDATE nexa_tenant SET status='suspended' WHERE id=? AND slug=?",[$tenant['id'],$tenant['slug']]);
            } else $execute('DELETE FROM nexa_tenant WHERE id=? AND slug=?',[$tenant['id'],$tenant['slug']]);
        }
    });
    echo json_encode(['cleaned'=>count($tenants)]); exit;
}

$tenant = $tenants[$input['index'] ?? 0];
$context = new TenantContext($tenant['id'],$tenant['slug'],'automation-acceptance','Automation acceptance fixture',$tenant['serviceId']);
$result = $contexts->runWith($context, function () use ($input,$command,$tenant,$factory,$em,$pdo,$execute,$atomic): mixed {
    $worker = $factory->create(WorkflowService::class);
    $webhooks = $factory->create(WorkflowWebhookService::class);
    $user = $em->getEntityById('User',$tenant['userId']);
    $admin = $factory->createWith(WorkflowService::class,['user'=>$user]);
    if (!empty($input['barrier']) && $command!=='hold-quota') { echo "READY\n"; flush(); if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Worker barrier was not released.'); }
    switch ($command) {
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
