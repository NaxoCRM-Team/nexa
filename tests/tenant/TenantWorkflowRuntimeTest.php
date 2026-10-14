<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Custom\Tools\Automation\WorkflowService;

final class WorkflowRollbackProbe implements \Espo\Core\Hook\Hook\AfterSave
{
    public int $failures=2;
    public function __construct(public string $contactId) {}
    public function afterSave(\Espo\ORM\Entity $entity,\Espo\ORM\Repository\Option\SaveOptions $options): void
    {
        if($entity->getId()===$this->contactId && $entity->get('department')==='Retry verified' && $this->failures-->0)throw new RuntimeException('Fixture native hook failure.');
    }
}

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$em = $container->getByClass(EntityManager::class);
$factory = $container->getByClass(InjectableFactory::class);
$contexts = $container->getByClass(TenantContextStore::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'workflow-runtime-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'workflow-runtime-test');
$other = new TenantContext($a->tenantId, $a->slug, 'workflow-service-test', '', '20000000-0000-4000-8000-000000000002');
$pdo = $em->getPDO();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {++$checks; if (!$condition) throw new RuntimeException($message);};
$blocked = static function (callable $callback, string $message) use ($assert): void {
    $denied = false;
    try {$callback();} catch (BadRequest | Forbidden | NotFound) {$denied = true;}
    $assert($denied, $message);
};
$tx = $em->getTransactionManager();
$tx->start();
try {
    foreach ([$a, $b] as $context) {
        $pdo->prepare("UPDATE nexa_tenant_service g INNER JOIN nexa_service_definition d ON d.id=g.service_id SET g.hard_limit_override=2000 WHERE g.tenant_id=? AND d.service_key='automation'")->execute([$context->tenantId]);
        // Hide existing schedules only inside this rollback-only fixture.
        $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=? AND status='published'")->execute([$context->tenantId, $context->serviceId]);
    }
    $suffix = bin2hex(random_bytes(5));
    $adminA = $contexts->runWith($a, fn () => $em->createEntity('User', ['userName' => 'workflow-a-' . $suffix, 'emailAddress' => 'workflow-a-' . $suffix . '@example.test', 'firstName' => 'Workflow', 'lastName' => 'A', 'type' => 'admin', 'isActive' => true]));
    $adminB = $contexts->runWith($b, fn () => $em->createEntity('User', ['userName' => 'workflow-b-' . $suffix, 'emailAddress' => 'workflow-b-' . $suffix . '@example.test', 'firstName' => 'Workflow', 'lastName' => 'B', 'type' => 'admin', 'isActive' => true]));
    $regularA = $contexts->runWith($a, fn () => $em->createEntity('User', ['userName' => 'workflow-staff-' . $suffix, 'emailAddress' => 'workflow-staff-' . $suffix . '@example.test', 'firstName' => 'Staff', 'type' => 'regular', 'isActive' => true]));
    $adminA = $contexts->runWith($a, fn () => $em->getEntityById('User', $adminA->getId()));
    $adminB = $contexts->runWith($b, fn () => $em->getEntityById('User', $adminB->getId()));
    $regularA = $contexts->runWith($a, fn () => $em->getEntityById('User', $regularA->getId()));
    $contactA = $contexts->runWith($a, fn () => $em->createEntity('Contact', ['firstName' => 'Workflow', 'lastName' => 'Contact A', 'leadScore' => 70]));
    $contactA2 = $contexts->runWith($a, fn () => $em->createEntity('Contact', ['firstName' => 'Workflow', 'lastName' => 'Contact A2', 'leadScore' => 10]));
    $contactB = $contexts->runWith($b, fn () => $em->createEntity('Contact', ['firstName' => 'Workflow', 'lastName' => 'Contact B', 'leadScore' => 70]));
    $serviceA = $factory->createWith(WorkflowService::class, ['user' => $adminA]);
    $serviceB = $factory->createWith(WorkflowService::class, ['user' => $adminB]);
    $regular = $factory->createWith(WorkflowService::class, ['user' => $regularA]);
    $worker = $factory->create(WorkflowService::class);
    $callA = static fn (callable $fn) => $contexts->runWith($a, $fn);
    $callB = static fn (callable $fn) => $contexts->runWith($b, $fn);
    $asObject = static fn (array $definition): stdClass => json_decode(json_encode($definition, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
    $base = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'branch', 'nodes' => [
        ['id' => 'branch', 'type' => 'branch', 'condition' => ['field' => 'leadScore', 'operator' => 'greater_than', 'value' => 50], 'yes' => 'wait', 'no' => 'end'],
        ['id' => 'wait', 'type' => 'delay', 'seconds' => 60, 'next' => 'end'],
        ['id' => 'end', 'type' => 'end'],
    ]];
    $matrixDrafts = [];
    $create = static function (array $definition, string $name = 'Workflow runtime acceptance') use ($callA, $serviceA, $asObject, &$matrixDrafts): array {
        $draft = $callA(fn () => $serviceA->save((object) ['name' => $name, 'definition' => $asObject($definition)]));
        $matrixDrafts[] = ['id' => $draft['id'], 'definition' => $definition];
        return $draft;
    };
    $draft = $create($base);
    $id = $draft['id'];
    $assert($draft['draftRevision'] === 1 && $draft['publishedVersion'] === null, 'New drafts must have a separate unpublished revision.');
    $blocked(fn () => $callA(fn () => $serviceA->enroll($id, $contactA->getId(), 'unpublished1')), 'Draft workflows must not enroll Contacts.');
    $published = $callA(fn () => $serviceA->publish($id, 1));
    $assert($published['publishedVersion'] === 1 && $published['status'] === 'published', 'Publication must create the first immutable version.');
    $assert($callA(fn () => $serviceA->publish($id, 1))['publishedVersion'] === 1, 'Publication replay must not add another version.');
    $blocked(fn () => $callA(fn () => $serviceA->save((object) ['name' => 'Stale', 'definition' => $asObject($base), 'expectedRevision' => 0], $id)), 'Stale draft revisions must be rejected.');
    $blocked(fn () => $callA(fn () => $serviceA->save((object) ['name' => 'Missing definition'])), 'Missing request definitions must return a validation error.');
    $run = $callA(fn () => $serviceA->enroll($id, $contactA->getId(), 'stable-request-001'));
    $assert($callA(fn () => $serviceA->enroll($id, $contactA->getId(), 'stable-request-001'))['id'] === $run['id'], 'Replay must return the same durable enrollment.');
    $blocked(fn () => $callA(fn () => $serviceA->enroll($id, $contactA2->getId(), 'stable-request-001')), 'A request key cannot identify another Contact.');
    $blocked(fn () => $callA(fn () => $serviceA->enroll($id, $contactA->getId(), 'another-request-001')), 'Never reenroll must reject another key for the same Contact.');
    $history = static fn (WorkflowService $service, string $workflowId, string $runId) => array_values(array_filter($service->history($workflowId)['runs'], static fn ($item) => $item['id'] === $runId))[0];
    $tick = static fn () => $callA(fn () => $worker->processDue());
    $tick();
    $assert($callA(fn () => $history($serviceA, $id, $run['id']))['nodeId'] === 'wait', 'A matching native numeric condition must select its yes branch.');
    $tick();
    $waiting = $callA(fn () => $history($serviceA, $id, $run['id']));
    $assert($waiting['status'] === 'waiting' && $waiting['nodeId'] === 'end', 'Delays must persist a future due time without sleeping.');
    $eventsBefore = count($callA(fn () => $serviceA->history($id))['events']);
    $tick();
    $assert(count($callA(fn () => $serviceA->history($id))['events']) === $eventsBefore, 'A repeated worker must not process a future due time.');
    $callA(fn () => $serviceA->control($id, 'paused'));
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=?')->execute([$run['id']]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $id, $run['id']))['status'] === 'waiting', 'Pause must hold due enrollments.');
    $assert($callA(fn () => $serviceA->enroll($id, $contactA->getId(), 'stable-request-001'))['id'] === $run['id'], 'Pause must not consume a second key or break replay.');
    $callA(fn () => $serviceA->control($id, 'published'));
    $tick();
    $assert($callA(fn () => $history($serviceA, $id, $run['id']))['status'] === 'completed', 'Resuming must preserve and complete the enrollment.');
    $eventsBefore = count($callA(fn () => $serviceA->history($id))['events']);
    $tick();
    $assert(count($callA(fn () => $serviceA->history($id))['events']) === $eventsBefore, 'Completed runs must not execute again.');
    $runNo = $callA(fn () => $serviceA->enroll($id, $contactA2->getId(), 'negative-branch-001'));
    $tick();
    $assert($callA(fn () => $history($serviceA, $id, $runNo['id']))['nodeId'] === 'end', 'A false condition must select its no branch.');
    $callA(fn () => $serviceA->cancel($id, $runNo['id']));
    $assert($callA(fn () => $history($serviceA, $id, $runNo['id']))['status'] === 'cancelled', 'Cancellation must stop an active enrollment.');
    $eventsBefore = count($callA(fn () => $serviceA->history($id))['events']);
    $callA(fn () => $serviceA->cancel($id, $runNo['id']));
    $assert(count($callA(fn () => $serviceA->history($id))['events']) === $eventsBefore, 'Cancellation replay must not duplicate audit events.');

    $blocked(fn () => $callB(fn () => $serviceB->detail($id)), 'Another tenant must not read a workflow.');
    $blocked(fn () => $callB(fn () => $serviceB->history($id)), 'Another tenant must not read execution history.');
    $blocked(fn () => $callB(fn () => $serviceB->cancel($id, $run['id'])), 'Another tenant must not cancel an enrollment.');
    $blocked(fn () => $callA(fn () => $serviceA->enroll($id, $contactB->getId(), 'foreign-contact-001')), 'Enrollment must reject another tenant Contact.');
    $blocked(fn () => $contexts->runWith($other, fn () => $serviceA->detail($id)), 'The same tenant must not read a different service workflow.');
    $blocked(fn () => $callA(fn () => $regular->workspace()), 'Ordinary staff must not configure workflows.');
    $blocked(fn () => $callA(fn () => $serviceA->processDue()), 'API administrators must not invoke the system worker.');
    $blocked(fn () => $callB(fn () => $serviceA->workspace()), 'An administrator cannot switch to another tenant context.');
    $foreign = $callB(fn () => $serviceB->save((object) ['name' => 'Independent tenant workflow', 'definition' => $asObject($base)]));
    $callB(fn () => $serviceB->publish($foreign['id'], 1));
    $runB = $callB(fn () => $serviceB->enroll($foreign['id'], $contactB->getId(), 'stable-request-001'));
    $assert($runB['id'] !== $run['id'], 'Identical external keys must remain independent between tenants.');
    $tick();
    $assert($callB(fn () => $history($serviceB, $foreign['id'], $runB['id']))['nodeId'] === 'branch', 'Tenant A workers must not advance tenant B runs.');

    $unsupported = $base; $unsupported['trigger']['event'] = 'api.event';
    $unsupportedDraft = $create($unsupported);
    $callA(fn ()=>$serviceA->publish($unsupportedDraft['id'],1));
    $apiRun=$callA(fn ()=>$serviceA->intakeEvent($unsupportedDraft['id'],$contactA2->getId(),'authenticated-api-event'));
    $assert($callA(fn ()=>$serviceA->intakeEvent($unsupportedDraft['id'],$contactA2->getId(),'authenticated-api-event'))['id']===$apiRun['id'],'Authenticated API event replay must return the same enrollment.');
    $blocked(fn ()=>$callA(fn ()=>$serviceA->enroll($unsupportedDraft['id'],$contactA->getId(),'manual-for-api-event')),'API event workflows cannot accept manual enrollment.');
    $blocked(fn ()=>$callB(fn ()=>$serviceB->intakeEvent($unsupportedDraft['id'],$contactB->getId(),'foreign-api-event')),'API intake must remain tenant-scoped.');
    $callA(fn ()=>$serviceA->cancel($unsupportedDraft['id'],$apiRun['id']));
    $unsupported = $base; $unsupported['nodes'][1] = ['id' => 'wait', 'type' => 'action', 'action' => 'email.send', 'settings' => ['sourceId' => 'example1', 'version' => 1], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'];
    $unsupportedDraft = $create($unsupported);
    $blocked(fn () => $callA(fn () => $serviceA->publish($unsupportedDraft['id'], 1)), 'Unwired actions must not publish or send mail.');
    $lifecycle = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'promote', 'nodes' => [
        ['id' => 'promote', 'type' => 'action', 'action' => 'lifecycle.set', 'settings' => ['stage' => 'SalesQualifiedLead'], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'],
        ['id' => 'end', 'type' => 'end'],
    ]];
    $actionDraft = $create($lifecycle, 'Native lifecycle action');
    $callA(fn () => $serviceA->publish($actionDraft['id'], 1));
    $actionRun = $callA(fn () => $serviceA->enroll($actionDraft['id'], $contactA2->getId(), 'native-lifecycle-001'));
    $beforeForeignStage = $callB(fn () => $em->getEntityById('Contact', $contactB->getId())->get('lifecycleStage'));
    $tick();
    $assert($callA(fn () => $em->getEntityById('Contact', $contactA2->getId())->get('lifecycleStage')) === 'SalesQualifiedLead', 'Actions must persist the lifecycle through the native Contact service.');
    $actionHistory = $callA(fn () => $serviceA->history($actionDraft['id']));
    $actionEvents = array_values(array_filter($actionHistory['events'], static fn ($event) => ($event['payload']['action'] ?? null) === 'lifecycle.set'));
    $assert(count($actionEvents) === 1 && $actionEvents[0]['payload']['changed'] === true && strlen($actionEvents[0]['payload']['actionKey']) === 64 && $actionEvents[0]['actorUserId'] === $adminA->getId(), 'Action audit must record a stable identity, stage change and execution actor.');
    $tick(); $tick();
    $actionEvents = array_filter($callA(fn () => $serviceA->history($actionDraft['id']))['events'], static fn ($event) => ($event['payload']['action'] ?? null) === 'lifecycle.set');
    $assert(count($actionEvents) === 1 && $callA(fn () => $history($serviceA, $actionDraft['id'], $actionRun['id']))['status'] === 'completed', 'Repeated worker ticks must not repeat a committed action.');
    $assert($callB(fn () => $em->getEntityById('Contact', $contactB->getId())->get('lifecycleStage')) === $beforeForeignStage, 'Native lifecycle actions must not modify another tenant.');
    $noopDraft = $create($lifecycle, 'No-op lifecycle action');
    $callA(fn () => $serviceA->publish($noopDraft['id'], 1));
    $callA(fn () => $serviceA->enroll($noopDraft['id'], $contactA2->getId(), 'native-lifecycle-noop'));
    $tick();
    $noopEvents = array_values(array_filter($callA(fn () => $serviceA->history($noopDraft['id']))['events'], static fn ($event) => ($event['payload']['action'] ?? null) === 'lifecycle.set'));
    $assert(count($noopEvents) === 1 && $noopEvents[0]['payload']['changed'] === false, 'An already matching lifecycle must advance without another native write.');
    $invalidAction = $lifecycle; $invalidAction['nodes'][0]['settings']['stage'] = 'InventedStage';
    $invalidDraft = $create($invalidAction);
    $blocked(fn () => $callA(fn () => $serviceA->publish($invalidDraft['id'], 1)), 'Unknown lifecycle stages must not publish.');
    $invalidAction = $lifecycle; $invalidAction['nodes'][0]['maxAttempts'] = 2;
    $invalidDraft = $create($invalidAction);
    $assert($callA(fn ()=>$serviceA->publish($invalidDraft['id'],1))['publishedVersion']===1,'Bounded transactional native retries must publish.');
    $cancelDraft = $create($lifecycle, 'Cancelled lifecycle action');
    $callA(fn () => $serviceA->publish($cancelDraft['id'], 1));
    $cancelRun = $callA(fn () => $serviceA->enroll($cancelDraft['id'], $contactA->getId(), 'native-lifecycle-cancel'));
    $beforeCancelledStage = $callA(fn () => $em->getEntityById('Contact', $contactA->getId())->get('lifecycleStage'));
    $callA(fn () => $serviceA->cancel($cancelDraft['id'], $cancelRun['id']));
    $tick();
    $assert($callA(fn () => $em->getEntityById('Contact', $contactA->getId())->get('lifecycleStage')) === $beforeCancelledStage, 'Cancelled enrollments must not apply native actions.');
    $nativeAction = static function (string $action, array $settings) use ($lifecycle): array {
        $definition = $lifecycle;
        $definition['nodes'][0]['action'] = $action;
        $definition['nodes'][0]['settings'] = $settings;
        return $definition;
    };
    $increaseDraft = $create($nativeAction('score.increase', ['amount' => 5]));
    $scoreContact=$callA(fn ()=>$em->createEntity('Contact',['firstName'=>'Scoring adapter','leadScore'=>70]));
    $callA(fn ()=>$serviceA->publish($increaseDraft['id'],1));
    $scoreRun=$callA(fn ()=>$serviceA->enroll($increaseDraft['id'],$scoreContact->getId(),'score-adapter-increase'));$tick();
    $assert((int)$callA(fn ()=>$em->getEntityById('Contact',$scoreContact->getId())->get('leadScore'))===75,'The dedicated scoring adapter must apply a bounded native update.');
    $tick();$assert((int)$callA(fn ()=>$em->getEntityById('Contact',$scoreContact->getId())->get('leadScore'))===75,'Worker replay must not apply the score adjustment twice.');
    $decreaseDraft = $create($nativeAction('score.decrease', ['amount' => 100]));
    $callA(fn ()=>$serviceA->publish($decreaseDraft['id'],1));$callA(fn ()=>$serviceA->enroll($decreaseDraft['id'],$scoreContact->getId(),'score-adapter-decrease'));$tick();
    $assert((int)$callA(fn ()=>$em->getEntityById('Contact',$scoreContact->getId())->get('leadScore'))===0,'Scoring decrements must clamp to zero.');
    $ownerDraft = $create($nativeAction('owner.assign', ['ownerId' => $regularA->getId()]));
    $callA(fn () => $serviceA->publish($ownerDraft['id'], 1));
    $callA(fn () => $serviceA->enroll($ownerDraft['id'], $contactA2->getId(), 'owner-assign-001'));
    $tick();
    $assert($callA(fn () => $em->getEntityById('Contact', $contactA2->getId())->get('assignedUserId')) === $regularA->getId(), 'Owner assignment must use native assignment validation.');
    $foreignOwner = $create($nativeAction('owner.assign', ['ownerId' => $adminB->getId()]));
    $blocked(fn () => $callA(fn () => $serviceA->publish($foreignOwner['id'], 1)), 'A workflow cannot assign a Contact to another tenant staff member.');
    $inactiveOwner = $create($nativeAction('owner.assign', ['ownerId' => $regularA->getId()]));
    $callA(fn () => $serviceA->publish($inactiveOwner['id'], 1));
    $inactiveRun = $callA(fn () => $serviceA->enroll($inactiveOwner['id'], $contactA->getId(), 'inactive-owner-001'));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$regularA->getId()]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $inactiveOwner['id'], $inactiveRun['id']))['status'] === 'failed', 'An owner disabled after publication must fail execution closed.');
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$regularA->getId()]);
    foreach (['all' => 'end', 'any' => 'wait'] as $mode => $expectedNode) {
        $group = $base;
        $group['nodes'][0]['condition'] = ['matchMode' => $mode, 'conditions' => [
            ['field' => 'firstName', 'operator' => 'equals', 'value' => 'Workflow'],
            ['field' => 'lastName', 'operator' => 'equals', 'value' => 'Does not match'],
        ]];
        $groupDraft = $create($group, 'Grouped conditions ' . $mode);
        $callA(fn () => $serviceA->publish($groupDraft['id'], 1));
        $groupRun = $callA(fn () => $serviceA->enroll($groupDraft['id'], $contactA->getId(), 'group-condition-' . $mode));
        $tick();
        $assert($callA(fn () => $history($serviceA, $groupDraft['id'], $groupRun['id']))['nodeId'] === $expectedNode, 'Grouped conditions must apply their explicit all/any rule.');
    }
    $group['nodes'][0]['condition']['conditions'][1]['field'] = 'tenantId';
    $hiddenGroup = $create($group);
    $blocked(fn () => $callA(fn () => $serviceA->publish($hiddenGroup['id'], 1)), 'Every grouped property must be authorized, even when another any condition matches.');
    $group['nodes'][0]['condition'] = ['matchMode' => 'all', 'conditions' => array_fill(0, 7, ['field' => 'firstName', 'operator' => 'has_value'])];
    $blocked(fn () => $create($group), 'Condition groups must not exceed six rules.');
    $group['nodes'][0]['condition'] = ['matchMode' => 'any', 'conditions' => [['matchMode' => 'all', 'conditions' => [['field' => 'firstName', 'operator' => 'has_value']]]]];
    $blocked(fn () => $create($group), 'Nested condition groups are not supported and must be rejected.');
    $unsupported = $base; $unsupported['nodes'][0]['condition']['field'] = 'tenantId';
    $unsupportedDraft = $create($unsupported);
    $blocked(fn () => $callA(fn () => $serviceA->publish($unsupportedDraft['id'], 1)), 'Ownership and hidden properties must not become condition inputs.');
    $assert($callA(fn () => $serviceA->control($unsupportedDraft['id'], 'archived'))['status'] === 'archived', 'An unpublished draft must be archivable.');
    $assert($callA(fn () => $serviceA->control($unsupportedDraft['id'], 'archived'))['status'] === 'archived', 'Archive replay must return the original state.');
    $blocked(fn () => $callA(fn () => $serviceA->control($unsupportedDraft['id'], 'published')), 'An archived draft cannot be resumed.');

    $pinContact = $callA(fn () => $em->createEntity('Contact', ['firstName' => 'Pinned version', 'leadScore' => 10]));
    $pinnedRun = $callA(fn () => $serviceA->enroll($id, $pinContact->getId(), 'pinned-version-001'));
    $changed = $base; $changed['nodes'][0]['condition']['value'] = 5;
    $revision = $callA(fn () => $serviceA->save((object) ['name' => 'Changed draft', 'definition' => $asObject($changed), 'expectedRevision' => 1], $id));
    $published2 = $callA(fn () => $serviceA->publish($id, $revision['draftRevision']));
    $assert($published2['publishedVersion'] === 2, 'Changed definitions must append a second immutable version.');
    $v1 = $pdo->prepare('SELECT definition_json FROM nexa_workflow_version WHERE workflow_id=? AND version_number=1'); $v1->execute([$id]);
    $assert(json_decode($v1->fetchColumn(), true)['nodes'][0]['condition']['value'] === 50, 'Publication must never overwrite the earlier definition.');
    $assert($callA(fn () => $serviceA->enroll($id, $contactA->getId(), 'stable-request-001'))['version'] === 1, 'Replay after publication must retain the original version.');
    $tick();
    $assert($callA(fn () => $history($serviceA, $id, $pinnedRun['id']))['nodeId'] === 'end', 'Already-enrolled Contacts must execute the original predicate after a new version is published.');

    $corrupt = $create($base); $callA(fn () => $serviceA->publish($corrupt['id'], 1));
    $corruptRun = $callA(fn () => $serviceA->enroll($corrupt['id'], $contactA->getId(), 'corrupt-version-001'));
    $pdo->prepare("UPDATE nexa_workflow_version SET definition_hash=REPEAT('0',64) WHERE workflow_id=?")->execute([$corrupt['id']]);
    $blocked(fn () => $callA(fn () => $serviceA->enroll($corrupt['id'], $contactA2->getId(), 'corrupt-version-002')), 'A corrupted published version must not accept new enrollments.');
    $tick();
    $assert($callA(fn () => $history($serviceA, $corrupt['id'], $corruptRun['id']))['status'] === 'failed', 'Version integrity failure must stop existing enrollments.');

    $wait = $base; $wait['nodes'][0]['type'] = 'wait_condition'; $wait['nodes'][0]['timeoutSeconds'] = 120; $wait['nodes'][0]['pollSeconds'] = 60;
    $waitDraft = $create($wait); $waitId = $waitDraft['id'];
    $callA(fn () => $serviceA->publish($waitId, 1));
    $waitingRun = $callA(fn () => $serviceA->enroll($waitId, $contactA2->getId(), 'wait-condition-001'));
    $tick();
    $assert($callA(fn () => $history($serviceA, $waitId, $waitingRun['id']))['nodeId'] === 'branch', 'False bounded-wait conditions must retain the current node.');
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND),node_entered_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 121 SECOND) WHERE id=?')->execute([$waitingRun['id']]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $waitId, $waitingRun['id']))['nodeId'] === 'end', 'Expired condition waits must select the timeout branch.');
    $timeoutEvents = $callA(fn () => $serviceA->history($waitId))['events'];
    $assert(count(array_filter($timeoutEvents, static fn ($event) => ($event['payload']['timedOut'] ?? false) === true)) === 1, 'Wait timeout decisions must have audit evidence.');

    $lateWait = $create($wait); $callA(fn () => $serviceA->publish($lateWait['id'], 1));
    $lateRun = $callA(fn () => $serviceA->enroll($lateWait['id'], $contactA->getId(), 'late-condition-001'));
    $pdo->prepare('UPDATE nexa_workflow_run SET node_entered_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 121 SECOND) WHERE id=?')->execute([$lateRun['id']]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $lateWait['id'], $lateRun['id']))['nodeId'] === 'end', 'An expired wait cannot claim a current match as evidence of a pre-deadline match.');

    $blockedService = $create($base); $callA(fn () => $serviceA->publish($blockedService['id'], 1));
    $blockedRun = $callA(fn () => $serviceA->enroll($blockedService['id'], $contactA->getId(), 'disabled-service-001'));
    $pdo->prepare("UPDATE nexa_tenant_service SET status='disabled' WHERE tenant_id=? AND service_id=?")->execute([$a->tenantId, $a->serviceId]);
    $assert($tick() === 0, 'Disabled service scopes must not process workflows.');
    $blocked(fn () => $callA(fn () => $serviceA->enroll($blockedService['id'], $contactA2->getId(), 'disabled-service-002')), 'Disabled services must reject new enrollments.');
    $pdo->prepare("UPDATE nexa_tenant_service SET status='active' WHERE tenant_id=? AND service_id=?")->execute([$a->tenantId, $a->serviceId]);
    $assert($callA(fn () => $history($serviceA, $blockedService['id'], $blockedRun['id']))['nodeId'] === 'branch', 'Disabled service handling must preserve the original execution state.');
    $pdo->prepare("UPDATE nexa_service_definition SET status='disabled' WHERE id=?")->execute([$a->serviceId]);
    $assert($tick() === 0, 'A globally disabled service must not process otherwise active tenant grants.');
    $blocked(fn () => $callA(fn () => $serviceA->workspace()), 'A globally disabled service must deny workflow management.');
    $pdo->prepare("UPDATE nexa_service_definition SET status='active' WHERE id=?")->execute([$a->serviceId]);
    $assert($callA(fn () => $history($serviceA, $blockedService['id'], $blockedRun['id']))['nodeId'] === 'branch', 'Global service disable must preserve enrollment state.');

    $numeric = $base; $numeric['nodes'][0]['condition'] = ['field' => 'leadScore', 'operator' => 'equals', 'value' => '70.00000000'];
    $numericDraft = $create($numeric); $callA(fn () => $serviceA->publish($numericDraft['id'], 1));
    $numericRun = $callA(fn () => $serviceA->enroll($numericDraft['id'], $contactA->getId(), 'numeric-equality-001'));
    $tick();
    $assert($callA(fn () => $history($serviceA, $numericDraft['id'], $numericRun['id']))['nodeId'] === 'wait', 'Numeric equality must normalize exact decimal representations without floats.');

    $database = $container->getByClass(\Espo\Core\Utils\Config::class)->get('database');
    $concurrent = new PDO('mysql:host=' . ($database['host'] ?? '127.0.0.1') . ';port=' . ($database['port'] ?? 3306) . ';dbname=' . $database['dbname'] . ';charset=utf8mb4', $database['user'], $database['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $concurrent->exec('SET SESSION innodb_lock_wait_timeout=1');
    $lockHeld = false;
    try {$concurrent->prepare('UPDATE nexa_workflow_run SET node_id=node_id WHERE id=?')->execute([$numericRun['id']]);}
    catch (PDOException $error) {$lockHeld = (int) ($error->errorInfo[1] ?? 0) === 1205;}
    $assert($lockHeld, 'A second connection must not overwrite a locked execution state.');
    $concurrent = null;

    $endOnly = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'after_completion'], 'entry' => 'end', 'nodes' => [['id' => 'end', 'type' => 'end']]];
    $request = static function (stdClass $body, array $params = []): \Espo\Core\Api\RequestWrapper {
        $psr = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/Nexa/workflows')->withHeader('Content-Type', 'application/json')->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        return new \Espo\Core\Api\RequestWrapper($psr, '', $params);
    };
    $apiClass = static fn (string $name) => $factory->createWith('Espo\\Custom\\Tools\\Automation\\Api\\' . $name, ['service' => $serviceA]);
    $workspaceApi = $apiClass('GetWorkspace');
    $workspace = json_decode((string) $callA(fn () => $workspaceApi->process($request(new stdClass())))->getBody(), true, 64, JSON_THROW_ON_ERROR);
    $assert($workspace['supportedActions'] === ['lifecycle.set', 'owner.assign', 'task.create', 'segment.add', 'segment.remove', 'case.create', 'notification.create', 'opportunity.create', 'email.send','webhook.send',...\Espo\Custom\Tools\Automation\WorkflowRecordActions::ACTIONS] && $workspace['supportedTriggers'] === ['manual','api.event','form.submitted','page.viewed','asset.downloaded','email.replied',...\Espo\Custom\Tools\Automation\WorkflowTriggerSource::EVENTS], 'The API catalogue must advertise only implemented actions and triggers.');
    $assert(!in_array($foreign['id'], array_column($workspace['list'], 'id'), true), 'Workflow catalogues must remain tenant-scoped.');
    $saveApi = $apiClass('PostSave');
    $apiDraft = json_decode((string) $callA(fn () => $saveApi->process($request((object) ['name' => 'API workflow', 'definition' => $asObject($endOnly)])))->getBody(), true, 64, JSON_THROW_ON_ERROR);
    $getApi = $apiClass('GetWorkflow');
    $apiId = $apiDraft['id'];
    $response = $callA(fn () => $getApi->process($request(new stdClass(), ['id' => $apiId])));
    $assert($response->getHeader('Cache-Control') === 'private, no-store', 'Workflow details must be private and not cached.');
    $pubApi = $apiClass('PostPublish');
    foreach ([(object) [], (object) ['expectedRevision' => '1'], (object) ['expectedRevision' => 1, 'executionUserId' => $adminB->getId()]] as $bad) $blocked(fn () => $callA(fn () => $pubApi->process($request($bad, ['id' => $apiId]))), 'Publication must reject missing revisions and execution-user overrides.');
    $callA(fn () => $pubApi->process($request((object) ['expectedRevision' => 1], ['id' => $apiId])));
    $enrollApi = $apiClass('PostEnroll');
    foreach ([(object) [], (object) ['contactId' => $contactA->getId(), 'requestKey' => 123], (object) ['contactId' => $contactA->getId(), 'requestKey' => 'api-request-001', 'tenantId' => $b->tenantId]] as $bad) $blocked(fn () => $callA(fn () => $enrollApi->process($request($bad, ['id' => $apiId]))), 'Enrollment must reject malformed inputs and ownership overrides.');
    $apiRun = json_decode((string) $callA(fn () => $enrollApi->process($request((object) ['contactId' => $contactA->getId(), 'requestKey' => 'api-request-001'], ['id' => $apiId])))->getBody(), true, 64, JSON_THROW_ON_ERROR);
    $controlApi = $apiClass('PostControl');
    $callA(fn () => $controlApi->process($request((object) ['status' => 'paused'], ['id' => $apiId])));
    $cancelApi = $apiClass('PostCancel');
    $blocked(fn () => $callA(fn () => $cancelApi->process($request((object) ['contactId' => $contactB->getId()], ['id' => $apiId, 'runId' => $apiRun['id']]))), 'Cancellation must not accept record overrides.');
    $callA(fn () => $cancelApi->process($request(new stdClass(), ['id' => $apiId, 'runId' => $apiRun['id']])));
    $historyApi = $apiClass('GetHistory');
    $apiHistory = json_decode((string) $callA(fn () => $historyApi->process($request(new stdClass(), ['id' => $apiId])))->getBody(), true, 64, JSON_THROW_ON_ERROR);
    $assert($apiHistory['runs'][0]['status'] === 'cancelled', 'Native request wrappers must exercise the persisted create/publish/enroll/cancel API path.');

    $business = $base;
    $weekday = (int) $pdo->query('SELECT WEEKDAY(UTC_TIMESTAMP())+1')->fetchColumn();
    $business['nodes'][0] = ['id' => 'branch', 'type' => 'business_hours', 'timezone' => 'UTC', 'weekdays' => [$weekday % 7 + 1], 'start' => '09:00', 'end' => '17:00', 'next' => 'end'];
    $business['nodes'] = [$business['nodes'][0], ['id' => 'end', 'type' => 'end']];
    $businessDraft = $create($business); $callA(fn () => $serviceA->publish($businessDraft['id'], 1));
    $businessRun = $callA(fn () => $serviceA->enroll($businessDraft['id'], $contactA->getId(), 'business-hours-001'));
    $tick();
    $businessState = $callA(fn () => $history($serviceA, $businessDraft['id'], $businessRun['id']));
    $assert($businessState['status'] === 'waiting' && $businessState['nodeId'] === 'branch', 'Business-hour waits must retain the node for validation at wake-up.');

    $deleted = $create($endOnly); $callA(fn () => $serviceA->publish($deleted['id'], 1));
    $deletedRun = $callA(fn () => $serviceA->enroll($deleted['id'], $pinContact->getId(), 'deleted-contact-001'));
    $pdo->prepare('UPDATE contact SET deleted=1 WHERE id=?')->execute([$pinContact->getId()]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $deleted['id'], $deletedRun['id']))['status'] === 'failed', 'Deleted Contacts must fail closed at execution time.');

    $re = $create($endOnly); $callA(fn () => $serviceA->publish($re['id'], 1));
    $r1 = $callA(fn () => $serviceA->enroll($re['id'], $contactA->getId(), 'reenroll-request-001'));
    $blocked(fn () => $callA(fn () => $serviceA->enroll($re['id'], $contactA->getId(), 'reenroll-request-002')), 'Active enrollments must not be duplicated.');
    $tick();
    $r2 = $callA(fn () => $serviceA->enroll($re['id'], $contactA->getId(), 'reenroll-request-002'));
    $assert($r1['id'] !== $r2['id'], 'After-completion policy must allow a new intentional enrollment.');
    $callA(fn () => $serviceA->control($re['id'], 'archived'));
    $assert($callA(fn () => $history($serviceA, $re['id'], $r2['id']))['status'] === 'cancelled', 'Archiving must cancel unfinished enrollments.');
    $blocked(fn () => $callA(fn () => $serviceA->publish($re['id'], 1)), 'Archived workflows must not be republished.');

    $failure = $create($endOnly); $callA(fn () => $serviceA->publish($failure['id'], 1));
    $failedRun = $callA(fn () => $serviceA->enroll($failure['id'], $contactA->getId(), 'inactive-owner-001'));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$adminA->getId()]);
    $tick();
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$adminA->getId()]);
    $assert($callA(fn () => $history($serviceA, $failure['id'], $failedRun['id']))['status'] === 'failed', 'Deactivated execution administrators must fail closed before advancing a run.');
    $events = $factory->create(\Espo\Custom\Tools\Event\BehaviorEventService::class);
    $eventInput = static fn (string $key, ?string $contactId, string $type = 'page.viewed') => (object) [
        'eventType' => $type, 'source' => 'workflow-acceptance', 'idempotencyKey' => $suffix . '-' . $key,
        'contactId' => $contactId, 'identityEvidence' => $contactId === null ? null : (object) ['type' => 'authenticated_session', 'verified' => true, 'reference' => $suffix],
        'consent' => (object) ['analytics' => 'granted'],
    ];
    $automatic = $lifecycle; $automatic['trigger']['event'] = 'page.viewed';
    $autoContact = $callA(fn () => $em->createEntity('Contact', ['firstName' => 'Automatic workflow']));
    $oldEvent = $callA(fn () => $events->ingest($eventInput('old', $autoContact->getId())));
    $autoDraft = $create($automatic, 'Canonical automatic enrollment');
    $callA(fn () => $serviceA->publish($autoDraft['id'], 1));
    $scanA = static fn () => $callA(fn () => $worker->processAutomatic());
    $blocked(fn () => $callA(fn () => $serviceA->processAutomatic()), 'Tenant administrators must not invoke the event enrollment worker.');
    $blocked(fn () => $callA(fn () => $serviceA->enroll($autoDraft['id'], $autoContact->getId(), 'manual-event-bypass')), 'Automatic workflows must reject manual enrollment bypasses.');
    $scanA();
    $assert(count($callA(fn () => $serviceA->history($autoDraft['id']))['runs']) === 0, 'Publication must not backfill historical behavior.');
    $newEvent = $callA(fn () => $events->ingest($eventInput('new', $autoContact->getId())));
    $utcReceipt = $pdo->prepare('SELECT received_at<=UTC_TIMESTAMP(6) FROM nexa_behavior_event WHERE id=?');
    $utcReceipt->execute([$newEvent['id']]);
    $assert((bool) $utcReceipt->fetchColumn(), 'Canonical ingestion must record UTC even when the database runs in a non-UTC time zone.');
    $scanA(); $scanA();
    $autoHistory = $callA(fn () => $serviceA->history($autoDraft['id']));
    $assert(count($autoHistory['runs']) === 1 && $autoHistory['runs'][0]['contactId'] === $autoContact->getId(), 'A committed identified event must enroll exactly once.');
    $enrolledEvent = array_values(array_filter($autoHistory['events'], static fn ($event) => $event['eventType'] === 'enrolled'))[0];
    $assert($enrolledEvent['payload']['triggerEventId'] === $newEvent['id'] && $enrolledEvent['actorUserId'] === $adminA->getId(), 'Automatic enrollment must audit its canonical event and pinned administrator.');
    $assert($callA(fn () => $events->ingest($eventInput('new', $autoContact->getId())))['duplicate'], 'Event ingestion replay must preserve canonical identity.');
    $tick(); $tick();
    $assert($callA(fn () => $em->getEntityById('Contact', $autoContact->getId())->get('lifecycleStage')) === 'SalesQualifiedLead', 'Automatic enrollment must execute the native Contact action.');
    $callA(fn () => $events->ingest($eventInput('not-eligible', $autoContact->getId())));
    $scanA(); $scanA();
    $autoHistory = $callA(fn () => $serviceA->history($autoDraft['id']));
    $skipped = array_values(array_filter($autoHistory['events'], static fn ($event) => $event['eventType'] === 'trigger.skipped'));
    $assert(count($autoHistory['runs']) === 1 && count($skipped) === 1 && $skipped[0]['payload']['reason'] === 'reenrollment_not_eligible', 'Ineligible events must have one durable skip receipt, not repeat every scan.');
    $callA(fn () => $serviceA->control($autoDraft['id'], 'paused'));
    $pauseContact = $callA(fn () => $em->createEntity('Contact', ['firstName' => 'Paused event']));
    $callA(fn () => $events->ingest($eventInput('paused', $pauseContact->getId())));
    $scanA();
    $assert(count($callA(fn () => $serviceA->history($autoDraft['id']))['runs']) === 1, 'Pause must hold automatic enrollment.');
    $callA(fn () => $serviceA->control($autoDraft['id'], 'published')); $scanA();
    $assert(count($callA(fn () => $serviceA->history($autoDraft['id']))['runs']) === 2, 'Resume must discover retained events received while paused.');
    $restrictedContact = $callA(fn () => $em->createEntity('Contact', ['firstName' => 'Restricted event']));
    $restrictedEvent = $callA(fn () => $events->ingest($eventInput('restricted', $restrictedContact->getId())));
    $pdo->prepare("UPDATE nexa_timeline_event SET visibility='restricted' WHERE source_entity_id=?")->execute([$restrictedEvent['id']]);
    $callA(fn () => $events->ingest($eventInput('anonymous', null)));
    $callA(fn () => $events->ingest($eventInput('wrong-type', $restrictedContact->getId(), 'asset.downloaded')));
    $scanA();
    $assert(count($callA(fn () => $serviceA->history($autoDraft['id']))['runs']) === 2, 'Anonymous, restricted and wrong-type events must not enroll.');
    $deletedContact = $callA(fn () => $em->createEntity('Contact', ['firstName' => 'Deleted event']));
    $callA(fn () => $events->ingest($eventInput('deleted', $deletedContact->getId())));
    $pdo->prepare('UPDATE contact SET deleted=1 WHERE id=?')->execute([$deletedContact->getId()]);
    $scanA();
    $assert(count($callA(fn () => $serviceA->history($autoDraft['id']))['runs']) === 2, 'Deleted Contacts must not enroll from retained events.');
    $before = count($callA(fn () => $serviceA->history($autoDraft['id']))['events']);
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$adminA->getId()]);
    $scanA(); $scanA();
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$adminA->getId()]);
    $blockedHistory = $callA(fn () => $serviceA->history($autoDraft['id']));
    $assert(count($blockedHistory['events']) === $before + 1, 'A blocked trigger must audit only its state transition rather than flood repeated failures.');
    $scanA();
    $assert(count($callA(fn () => $serviceA->history($autoDraft['id']))['events']) === $before + 2, 'Restored execution access must audit recovery.');
    $autoB = $automatic; $autoB['trigger']['event'] = 'form.submitted';
    $draftB = $callB(fn () => $serviceB->save((object) ['name' => 'Tenant B form', 'definition' => $asObject($autoB)]));
    $callB(fn () => $serviceB->publish($draftB['id'], 1));
    $callB(fn () => $events->ingest($eventInput('form', $contactB->getId(), 'form.submitted')));
    $scanA();
    $assert(count($callB(fn () => $serviceB->history($draftB['id']))['runs']) === 0, 'Tenant A scans must never process tenant B source events.');
    $callB(fn () => $worker->processAutomatic());
    $assert(count($callB(fn () => $serviceB->history($draftB['id']))['runs']) === 1, 'Tenant B must independently enroll its own identified form event.');
    foreach (['asset.downloaded','email.replied'] as $trigger) {
        $simple = $endOnly; $simple['trigger']['event'] = $trigger;
        $simpleDraft = $create($simple, 'Canonical ' . $trigger);
        $callA(fn () => $serviceA->publish($simpleDraft['id'], 1));
        $callA(fn () => $events->ingest($eventInput($trigger, $autoContact->getId(), $trigger)));
        $scanA();
        $assert(count($callA(fn () => $serviceA->history($simpleDraft['id']))['runs']) === 1, 'Every advertised canonical trigger must enroll its own event type.');
        $tick();
        $callA(fn () => $events->ingest($eventInput($trigger . '-reenroll', $autoContact->getId(), $trigger)));
        $scanA();
        $assert(count($callA(fn () => $serviceA->history($simpleDraft['id']))['runs']) === 2, 'Completed automatic workflows must accept a distinct subsequent event when allowed.');
        $scanA();
        $assert(count($callA(fn () => $serviceA->history($simpleDraft['id']))['runs']) === 2, 'Distinct-event reenrollment must still deduplicate repeated scans.');
    }
    $formWorkspace = $factory->create(\Espo\Custom\Tools\Form\FormWorkspaceService::class);
    $createdForm = $callB(fn () => $formWorkspace->save((object) ['name' => 'Workflow Contact capture', 'fields' => [(object) ['name' => 'firstName', 'required' => true]], 'successMessage' => 'Thank you.']));
    $formId = $createdForm['savedId'];
    $callB(fn () => $formWorkspace->publish($formId));
    $capturedContact = $callB(fn () => $em->createEntity('Contact', ['firstName' => 'Captured Contact']));
    $formRuntime = $factory->create(\Espo\Custom\Tools\Form\PublicFormRuntimeService::class);
    $hook = ['targetType' => 'Contact', 'targetId' => $capturedContact->getId(), 'data' => (object) ['nexaSubmissionKey' => 'c1000000-0000-4000-8000-000000000001']];
    $callB(fn () => $formRuntime->recordSubmission($em->getEntityById('LeadCapture', $formId), $hook));
    $callB(fn () => $formRuntime->recordSubmission($em->getEntityById('LeadCapture', $formId), $hook));
    $callB(fn () => $worker->processAutomatic());
    $assert(count($callB(fn () => $serviceB->history($draftB['id']))['runs']) === 2, 'The native Contact submission hook must project one canonical event and enroll once.');
    $beforeBridge = $pdo->prepare("SELECT COUNT(*) FROM nexa_behavior_event WHERE tenant_id=? AND service_id=? AND source='form-capture'");
    $beforeBridge->execute([$b->tenantId,$b->serviceId]);
    $assert((int) $beforeBridge->fetchColumn() === 1, 'Form submission hook replay must not duplicate canonical events.');
    $foreignHook = ['targetType' => 'Contact', 'targetId' => $contactA->getId(), 'data' => (object) ['nexaSubmissionKey' => 'c1000000-0000-4000-8000-000000000002']];
    $blocked(fn () => $callB(fn () => $formRuntime->recordSubmission($em->getEntityById('LeadCapture', $formId), $foreignHook)), 'The form bridge must reject a foreign Contact.');
    $rollbackForm = $pdo->prepare('SELECT COUNT(*) FROM nexa_form_event WHERE submission_key=?');
    $rollbackForm->execute(['c1000000-0000-4000-8000-000000000002']);
    $assert((int) $rollbackForm->fetchColumn() === 0, 'A failed canonical form projection must roll back its form evidence too.');
    $burst = $endOnly; $burst['trigger']['event'] = 'form.submitted';
    $burstDraft = $create($burst, 'Bounded event scan');
    $callA(fn () => $serviceA->publish($burstDraft['id'], 1));
    for ($index = 0; $index < 26; $index++) $callA(fn () => $events->ingest($eventInput('burst-' . $index, $autoContact->getId(), 'form.submitted')));
    $scanA();
    $receiptCount = $pdo->prepare('SELECT COUNT(*) FROM nexa_workflow_trigger_receipt WHERE workflow_id=? AND tenant_id=? AND service_id=?');
    $receiptCount->execute([$burstDraft['id'], $a->tenantId, $a->serviceId]);
    $assert((int) $receiptCount->fetchColumn() === 25, 'One workflow event scan must not exceed its 25-event batch bound.');
    $scanA();
    $receiptCount->execute([$burstDraft['id'], $a->tenantId, $a->serviceId]);
    $assert((int) $receiptCount->fetchColumn() === 26, 'Later scans must drain remaining events without a lossy high-water cursor.');
    $scopeGrant = $pdo->prepare("UPDATE nexa_tenant_service SET status=? WHERE tenant_id=? AND service_id=?");
    $scopeGrant->execute(['inactive', $a->tenantId, $a->serviceId]);
    $assert($scanA() === 0, 'Disabled tenant service scopes must hold automatic enrollment.');
    $scopeGrant->execute(['active', $a->tenantId, $a->serviceId]);
    $callA(fn () => $serviceA->control($autoDraft['id'], 'archived'));
    $scanA();
    $assert($callA(fn () => $serviceA->detail($autoDraft['id']))['status'] === 'archived', 'Archive must prevent further event scans.');
    $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=? AND status='published'")->execute([$a->tenantId, $a->serviceId]);
    $custom = $factory->createWith(\Espo\Custom\Tools\Customization\CustomizationService::class, ['user' => $adminA, 'acl' => $container->getByClass(AclManager::class)->createUserAcl($adminA)]);
    $key = 'workflow_amount_' . $suffix;
    $number = $callA(fn () => $custom->saveDefinition('field', ['entityType' => 'Contact', 'fieldKey' => $key, 'label' => 'Workflow amount ' . $suffix, 'dataType' => 'number']));
    $fieldName = 'custom:' . $key;
    $catalog = $callA(fn () => $serviceA->workspace())['conditionFields'];
    $assert(in_array($fieldName, array_column($catalog, 'name'), true), 'Enabled tenant numeric properties must appear in the workflow editor.');
    $assert(!in_array($fieldName, array_column($callB(fn () => $serviceB->workspace())['conditionFields'], 'name'), true), 'Custom property catalogues must not cross tenants.');
    $pdo->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_number,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)')->execute([$a->tenantId, $a->serviceId, $number['id'], 'Contact', $contactA->getId(), '9007199254740993.12345678', $adminA->getId(), $adminA->getId()]);
    $numeric = $base;
    $numeric['nodes'][0]['condition'] = ['field' => $fieldName, 'operator' => 'greater_than', 'value' => '9007199254740993.12345677'];
    $numericDraft = $create($numeric, 'Exact custom numeric condition');
    $callA(fn () => $serviceA->publish($numericDraft['id'], 1));
    $numericRun = $callA(fn () => $serviceA->enroll($numericDraft['id'], $contactA->getId(), 'numeric-condition-one'));
    $missingRun = $callA(fn () => $serviceA->enroll($numericDraft['id'], $contactA2->getId(), 'numeric-condition-two'));
    $tick();
    $assert($callA(fn () => $history($serviceA, $numericDraft['id'], $numericRun['id']))['nodeId'] === 'wait', 'Custom numbers must compare exactly above the floating-point integer boundary.');
    $assert($callA(fn () => $history($serviceA, $numericDraft['id'], $missingRun['id']))['nodeId'] === 'end', 'Missing custom numbers must take the nonmatching branch.');
    $callA(fn () => $custom->archive('field', $number['id']));
    $blocked(fn () => $callA(fn () => $serviceA->publish($numericDraft['id'], 1)), 'Archived custom properties must fail publication revalidation.');
    $tick();
    $assert($callA(fn () => $history($serviceA, $numericDraft['id'], $numericRun['id']))['status'] === 'failed', 'Archiving a custom property must also block already-enrolled execution.');

    $behavior = $base;
    $behavior['nodes'][0]['condition'] = ['field' => 'behavior:page.viewed', 'operator' => 'within_days', 'value' => '7'];
    $behaviorDraft = $create($behavior, 'Recorded activity window');
    $callA(fn () => $serviceA->publish($behaviorDraft['id'], 1));
    $callA(fn () => $events->ingest($eventInput('condition-visit', $contactA->getId(), 'page.viewed')));
    $behaviorRun = $callA(fn () => $serviceA->enroll($behaviorDraft['id'], $contactA->getId(), 'behavior-condition-one'));
    $noBehaviorRun = $callA(fn () => $serviceA->enroll($behaviorDraft['id'], $contactA2->getId(), 'behavior-condition-two'));
    $tick();
    $assert($callA(fn () => $history($serviceA, $behaviorDraft['id'], $behaviorRun['id']))['nodeId'] === 'wait', 'A recorded matching Contact visit must select the matching branch.');
    $assert($callA(fn () => $history($serviceA, $behaviorDraft['id'], $noBehaviorRun['id']))['nodeId'] === 'end', 'Another Contact must not inherit recorded behavior.');
    foreach (['0', '366', '1.5'] as $days) {
        $invalid = $behavior; $invalid['nodes'][0]['condition']['value'] = $days;
        $blocked(fn () => $create($invalid), 'Behavior windows must be bounded whole days.');
    }
    $invalid = $behavior; $invalid['nodes'][0]['condition']['field'] = 'behavior:unknown.event';
    $invalidDraft = $create($invalid);
    $blocked(fn () => $callA(fn () => $serviceA->publish($invalidDraft['id'], 1)), 'Unknown behavioral sources must not become executable.');
    $taskDefinition = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'task', 'nodes' => [
        ['id' => 'task', 'type' => 'action', 'action' => 'task.create', 'settings' => ['name' => 'Workflow follow-up ' . $suffix, 'ownerId' => $regularA->getId()], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'],
        ['id' => 'end', 'type' => 'end'],
    ]];
    $taskDraft = $create($taskDefinition);
    $callA(fn () => $serviceA->publish($taskDraft['id'], 1));
    $taskRun = $callA(fn () => $serviceA->enroll($taskDraft['id'], $contactA->getId(), 'create-native-task-one'));
    $tick();
    $taskQuery = $pdo->prepare('SELECT id,assigned_user_id,parent_id,parent_type FROM task WHERE tenant_id=? AND service_id=? AND name=? AND deleted=0');
    $taskQuery->execute([$a->tenantId, $a->serviceId, 'Workflow follow-up ' . $suffix]);
    $tasks = $taskQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($tasks) === 1 && $tasks[0]['parent_id'] === $contactA->getId() && $tasks[0]['parent_type'] === 'Contact' && $tasks[0]['assigned_user_id'] === $regularA->getId(), 'Task actions must use native scoped records linked to the enrolled Contact and chosen staff owner.');
    $taskEvents = $callA(fn () => $serviceA->history($taskDraft['id']))['events'];
    $completed = array_values(array_filter($taskEvents, fn ($item) => $item['eventType'] === 'step.completed' && $item['nodeId'] === 'task'));
    $assert(count($completed) === 1 && $completed[0]['payload']['taskId'] === $tasks[0]['id'], 'Task creation must audit the native result together with node advancement.');
    $tick(); $tick();
    $taskQuery->execute([$a->tenantId, $a->serviceId, 'Workflow follow-up ' . $suffix]);
    $assert(count($taskQuery->fetchAll()) === 1, 'Repeated processing must not create another task for the same node.');
    $foreignTask = $taskDefinition; $foreignTask['nodes'][0]['settings']['ownerId'] = $adminB->getId();
    $foreignTaskDraft = $create($foreignTask);
    $blocked(fn () => $callA(fn () => $serviceA->publish($foreignTaskDraft['id'], 1)), 'Native tasks must not be assigned to another tenant staff member.');
    $inactiveTaskDraft = $create($taskDefinition, 'Recheck task assignment');
    $callA(fn () => $serviceA->publish($inactiveTaskDraft['id'], 1));
    $inactiveTaskRun = $callA(fn () => $serviceA->enroll($inactiveTaskDraft['id'], $contactA2->getId(), 'inactive-task-owner'));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$regularA->getId()]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $inactiveTaskDraft['id'], $inactiveTaskRun['id']))['status'] === 'failed', 'Task assignment targets must be revalidated at execution, not only publication.');
    $taskQuery->execute([$a->tenantId, $a->serviceId, 'Workflow follow-up ' . $suffix]);
    $assert(count($taskQuery->fetchAll()) === 1, 'Rejected task ownership must not leave a partial native record.');
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$regularA->getId()]);
    $segmentsA = $factory->createWith(\Espo\Custom\Tools\Segment\SegmentWorkspaceService::class, ['user' => $adminA, 'acl' => $container->getByClass(AclManager::class)->createUserAcl($adminA)]);
    $segmentsB = $factory->createWith(\Espo\Custom\Tools\Segment\SegmentWorkspaceService::class, ['user' => $adminB, 'acl' => $container->getByClass(AclManager::class)->createUserAcl($adminB)]);
    $list = $callA(fn () => $segmentsA->save((object) ['name' => 'Workflow static list ' . $suffix, 'type' => 'static']));
    $listB = $callB(fn () => $segmentsB->save((object) ['name' => 'Foreign static list ' . $suffix, 'type' => 'static']));
    $assert(in_array($list['id'], array_column($callA(fn () => $serviceA->workspace())['staticLists'], 'id'), true), 'The workflow list selector must include its tenant static lists.');
    $assert(!in_array($listB['id'], array_column($callA(fn () => $serviceA->workspace())['staticLists'], 'id'), true), 'The workflow list selector must exclude foreign lists.');
    $listAction = static fn (string $action, string $listId): array => ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'list', 'nodes' => [
        ['id' => 'list', 'type' => 'action', 'action' => $action, 'settings' => ['segmentId' => $listId], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'], ['id' => 'end', 'type' => 'end'],
    ]];
    $addList = $create($listAction('segment.add', $list['id']));
    $callA(fn () => $serviceA->publish($addList['id'], 1));
    $callA(fn () => $serviceA->enroll($addList['id'], $contactA->getId(), 'add-list-contact-one'));
    $tick();
    $membership = $pdo->prepare('SELECT deleted,opted_out FROM contact_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND contact_id=?');
    $membership->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $member = $membership->fetch(PDO::FETCH_ASSOC);
    $assert($member && !(bool) $member['deleted'], 'List actions must use the native scoped Contact membership.');
    $count = $pdo->prepare('SELECT member_count FROM nexa_segment_definition WHERE tenant_id=? AND service_id=? AND target_list_id=?');
    $count->execute([$a->tenantId,$a->serviceId,$list['id']]);
    $assert((int) $count->fetchColumn() === 1, 'Static list counts must update together with native membership.');
    $audit = $pdo->prepare('SELECT COUNT(*) FROM nexa_segment_membership_event WHERE tenant_id=? AND service_id=? AND target_list_id=? AND contact_id=?');
    $audit->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $assert((int) $audit->fetchColumn() === 1, 'Workflow entry must appear in existing segment membership history.');
    $tick(); $tick();
    $audit->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $assert((int) $audit->fetchColumn() === 1, 'Reprocessing must not duplicate a committed membership event.');
    $pdo->prepare('UPDATE contact_target_list SET opted_out=1 WHERE tenant_id=? AND service_id=? AND target_list_id=? AND contact_id=?')->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $removeList = $create($listAction('segment.remove', $list['id']));
    $callA(fn () => $serviceA->publish($removeList['id'], 1));
    $callA(fn () => $serviceA->enroll($removeList['id'], $contactA->getId(), 'remove-list-contact-one'));
    $tick();
    $membership->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $member = $membership->fetch(PDO::FETCH_ASSOC);
    $assert($member && (bool) $member['deleted'] && (bool) $member['opted_out'], 'Native removal must retain the existing list opt-out tombstone.');
    $readdList = $create($listAction('segment.add', $list['id']));
    $callA(fn () => $serviceA->publish($readdList['id'], 1));
    $callA(fn () => $serviceA->enroll($readdList['id'], $contactA->getId(), 'readd-list-contact-one'));
    $tick();
    $membership->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $member = $membership->fetch(PDO::FETCH_ASSOC);
    $assert($member && !(bool) $member['deleted'] && (bool) $member['opted_out'], 'Readding a Contact must not reset opt-out or imply permission to send.');
    $consent = $factory->createWith(\Espo\Custom\Tools\Consent\ConsentService::class, ['user' => $adminA, 'acl' => $container->getByClass(AclManager::class)->createUserAcl($adminA)]);
    $purposes = $callA(fn () => $consent->getWorkspace())['purposes'];
    $marketingPurpose = array_values(array_filter($purposes, fn ($purpose) => $purpose['purposeKey'] === 'marketing_communications'))[0];
    $callA(fn () => $consent->recordDecision((object) ['contactId' => $contactA->getId(), 'purposeId' => $marketingPurpose['id'], 'channel' => 'email', 'status' => 'granted', 'legalBasis' => 'FreelyGivenConsent', 'source' => 'manual', 'evidenceNote' => 'Rollback-only workflow eligibility fixture.']));
    $callA(fn () => $segmentsA->getWorkspace());
    $eligible = $pdo->prepare('SELECT eligible_count FROM nexa_segment_definition WHERE tenant_id=? AND service_id=? AND target_list_id=?');
    $eligible->execute([$a->tenantId,$a->serviceId,$list['id']]);
    $assert((int) $eligible->fetchColumn() === 0, 'Static list eligibility must exclude a list-level opt-out even when purpose consent is granted.');
    $pdo->prepare('UPDATE contact_target_list SET opted_out=0 WHERE tenant_id=? AND service_id=? AND target_list_id=? AND contact_id=?')->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $callA(fn () => $segmentsA->getWorkspace());
    $eligible->execute([$a->tenantId,$a->serviceId,$list['id']]);
    $assert((int) $eligible->fetchColumn() === 1, 'The opt-out eligibility check must not suppress a consenting non-opted-out member.');
    $pdo->prepare('UPDATE contact_target_list SET opted_out=1 WHERE tenant_id=? AND service_id=? AND target_list_id=? AND contact_id=?')->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $sameList = $create($listAction('segment.add', $list['id']));
    $callA(fn () => $serviceA->publish($sameList['id'], 1));
    $sameRun = $callA(fn () => $serviceA->enroll($sameList['id'], $contactA->getId(), 'already-listed-contact'));
    $tick();
    $sameEvents = $callA(fn () => $serviceA->history($sameList['id']))['events'];
    $sameCompleted = array_values(array_filter($sameEvents, fn ($event) => $event['eventType'] === 'step.completed' && $event['nodeId'] === 'list'));
    $assert(count($sameCompleted) === 1 && $sameCompleted[0]['payload']['changed'] === false, 'Already-matching membership must advance without a redundant native relation write.');
    $audit->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $assert((int) $audit->fetchColumn() === 3, 'No-op membership must not invent another entered event.');
    $archiveList = $create($listAction('segment.remove', $list['id']));
    $callA(fn () => $serviceA->publish($archiveList['id'], 1));
    $archiveRun = $callA(fn () => $serviceA->enroll($archiveList['id'], $contactA->getId(), 'archived-list-hold'));
    $pdo->prepare("UPDATE nexa_segment_definition SET status='archived' WHERE tenant_id=? AND service_id=? AND target_list_id=?")->execute([$a->tenantId,$a->serviceId,$list['id']]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $archiveList['id'], $archiveRun['id']))['status'] === 'failed', 'Archiving a list after publication must prevent execution.');
    $membership->execute([$a->tenantId,$a->serviceId,$list['id'],$contactA->getId()]);
    $assert(!(bool) $membership->fetch(PDO::FETCH_ASSOC)['deleted'], 'Rejected archived-list execution must not partially unlink a Contact.');
    $pdo->prepare("UPDATE nexa_segment_definition SET status='active' WHERE tenant_id=? AND service_id=? AND target_list_id=?")->execute([$a->tenantId,$a->serviceId,$list['id']]);
    $foreignList = $create($listAction('segment.add', $listB['id']));
    $blocked(fn () => $callA(fn () => $serviceA->publish($foreignList['id'], 1)), 'Foreign list references must fail publication.');
    $pdo->prepare("UPDATE nexa_segment_definition SET segment_type='dynamic' WHERE tenant_id=? AND service_id=? AND target_list_id=?")->execute([$a->tenantId,$a->serviceId,$list['id']]);
    $dynamicList = $create($listAction('segment.add', $list['id']));
    $blocked(fn () => $callA(fn () => $serviceA->publish($dynamicList['id'], 1)), 'Workflow membership edits must not override dynamic list rules.');
    $caseDefinition = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'case', 'nodes' => [
        ['id' => 'case', 'type' => 'action', 'action' => 'case.create', 'settings' => ['name' => 'Workflow support ' . $suffix, 'ownerId' => $regularA->getId(), 'priority' => 'High', 'category' => 'Technical'], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'], ['id' => 'end', 'type' => 'end'],
    ]];
    $caseDraft = $create($caseDefinition);
    $callA(fn () => $serviceA->publish($caseDraft['id'], 1));
    $caseRun = $callA(fn () => $serviceA->enroll($caseDraft['id'], $contactA->getId(), 'native-support-case-one'));
    $tick();
    $caseQuery = $pdo->prepare('SELECT id,assigned_user_id,contact_id,priority,category,sla_status,sla_policy_id,first_response_due_at,resolution_due_at FROM `case` WHERE tenant_id=? AND service_id=? AND name=? AND deleted=0');
    $caseQuery->execute([$a->tenantId,$a->serviceId,'Workflow support ' . $suffix]);
    $cases = $caseQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($cases) === 1 && $cases[0]['contact_id'] === $contactA->getId() && $cases[0]['assigned_user_id'] === $regularA->getId(), 'Workflow Cases must be native scoped records linked to the enrolled Contact and chosen staff owner.');
    $assert($cases[0]['priority'] === 'High' && $cases[0]['category'] === 'Technical', 'Case priority and category must persist through native validation.');
    $assert($cases[0]['sla_status'] === 'Running' && $cases[0]['sla_policy_id'] && $cases[0]['first_response_due_at'] && $cases[0]['resolution_due_at'], 'Workflow Cases must initialize the existing native SLA lifecycle.');
    $caseEntity = $callA(fn () => $em->getEntityById('Case', $cases[0]['id']));
    $assert($callA(fn () => $em->getRDBRepository('Case')->getRelation($caseEntity, 'contacts')->isRelated($contactA)), 'Workflow Cases must retain the native many-contact association.');
    $caseEvents = $callA(fn () => $serviceA->history($caseDraft['id']))['events'];
    $caseSteps = array_values(array_filter($caseEvents, fn ($event) => $event['eventType'] === 'step.completed' && $event['nodeId'] === 'case'));
    $assert(count($caseSteps) === 1 && $caseSteps[0]['payload']['caseId'] === $cases[0]['id'], 'Case creation must audit its native ID together with step advancement.');
    $tick(); $tick();
    $caseQuery->execute([$a->tenantId,$a->serviceId,'Workflow support ' . $suffix]);
    $assert(count($caseQuery->fetchAll()) === 1, 'Repeated processing must not create another Case for a committed step.');
    $foreignCase = $caseDefinition; $foreignCase['nodes'][0]['settings']['ownerId'] = $adminB->getId();
    $foreignCaseDraft = $create($foreignCase);
    $blocked(fn () => $callA(fn () => $serviceA->publish($foreignCaseDraft['id'], 1)), 'Case actions must reject another tenant owner.');
    foreach (['priority', 'category'] as $field) {
        $invalidCase = $caseDefinition; $invalidCase['nodes'][0]['settings'][$field] = 'Unsupported value';
        $invalidCaseDraft = $create($invalidCase);
        $blocked(fn () => $callA(fn () => $serviceA->publish($invalidCaseDraft['id'], 1)), 'Case actions must reject unsupported native options.');
    }
    $inactiveCaseDraft = $create($caseDefinition);
    $callA(fn () => $serviceA->publish($inactiveCaseDraft['id'], 1));
    $inactiveCaseRun = $callA(fn () => $serviceA->enroll($inactiveCaseDraft['id'], $contactA2->getId(), 'inactive-case-owner'));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$regularA->getId()]);
    $tick();
    $assert($callA(fn () => $history($serviceA, $inactiveCaseDraft['id'], $inactiveCaseRun['id']))['status'] === 'failed', 'Case ownership must be revalidated when the step executes.');
    $caseQuery->execute([$a->tenantId,$a->serviceId,'Workflow support ' . $suffix]);
    $assert(count($caseQuery->fetchAll()) === 1, 'Invalid Case ownership must not leave a partial record.');
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$regularA->getId()]);
    $minimalCase = $caseDefinition;
    $minimalCase['nodes'][0]['settings'] = ['name' => 'Default workflow Case ' . $suffix, 'category' => ''];
    $minimalDraft = $create($minimalCase);
    $callA(fn () => $serviceA->publish($minimalDraft['id'], 1));
    $minimalRun = $callA(fn () => $serviceA->enroll($minimalDraft['id'], $contactA2->getId(), 'default-case-values'));
    $tick();
    $caseQuery->execute([$a->tenantId,$a->serviceId,'Default workflow Case ' . $suffix]);
    $minimalRows = $caseQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($minimalRows) === 1 && $minimalRows[0]['assigned_user_id'] === $adminA->getId(), 'Omitted Case owners must use the publishing execution administrator.');
    $nativePriority = $container->getByClass(\Espo\Core\Utils\Metadata::class)->get(['entityDefs','Case','fields','priority','default']);
    $assert($minimalRows[0]['priority'] === $nativePriority && $minimalRows[0]['category'] === '', 'Omitted priority must use the native default and explicit empty category must remain supported.');
    $assert($callA(fn () => $serviceA->enroll($minimalDraft['id'], $contactA2->getId(), 'default-case-values'))['id'] === $minimalRun['id'], 'Case workflow enrollment replay must retain the same durable run.');
    $cancelCase = $caseDefinition;
    $cancelCase['nodes'][0]['settings']['name'] = 'Cancelled workflow Case ' . $suffix;
    $cancelCaseDraft = $create($cancelCase);
    $callA(fn () => $serviceA->publish($cancelCaseDraft['id'], 1));
    $cancelCaseRun = $callA(fn () => $serviceA->enroll($cancelCaseDraft['id'], $contactA->getId(), 'cancel-case-before-create'));
    $callA(fn () => $serviceA->cancel($cancelCaseDraft['id'], $cancelCaseRun['id']));
    $tick();
    $caseQuery->execute([$a->tenantId,$a->serviceId,'Cancelled workflow Case ' . $suffix]);
    $assert(count($caseQuery->fetchAll()) === 0, 'Cancellation before Case creation must leave no native support record.');
    $notifyDefinition = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'notify', 'nodes' => [
        ['id' => 'notify', 'type' => 'action', 'action' => 'notification.create', 'settings' => ['ownerId' => $adminA->getId(), 'message' => 'Workflow notice ' . $suffix], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'], ['id' => 'end', 'type' => 'end'],
    ]];
    $notifyDraft = $create($notifyDefinition);
    $callA(fn () => $serviceA->publish($notifyDraft['id'], 1));
    $notifyRun = $callA(fn () => $serviceA->enroll($notifyDraft['id'], $contactA->getId(), 'native-notification'));
    $tick();
    $notifyQuery = $pdo->prepare('SELECT id,user_id,related_type,related_id,created_by_id FROM notification WHERE tenant_id=? AND service_id=? AND message=?');
    $notifyQuery->execute([$a->tenantId,$a->serviceId,'Workflow notice ' . $suffix]);
    $notifications = $notifyQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($notifications) === 1 && $notifications[0]['user_id'] === $adminA->getId() && $notifications[0]['related_type'] === 'Contact' && $notifications[0]['related_id'] === $contactA->getId(), 'Workflow notifications must use the native bell and scoped recipient and Contact.');
    $assert($notifications[0]['created_by_id'] === $adminA->getId(), 'Notifications must retain the execution administrator.');
    $notifyEvents = $callA(fn () => $serviceA->history($notifyDraft['id']))['events'];
    $notifySteps = array_values(array_filter($notifyEvents, fn ($event) => $event['eventType'] === 'step.completed' && $event['nodeId'] === 'notify'));
    $assert(count($notifySteps) === 1 && $notifySteps[0]['payload']['notificationId'] === $notifications[0]['id'], 'Notification creation and advancement must audit the native ID atomically.');
    $tick(); $tick();
    $notifyQuery->execute([$a->tenantId,$a->serviceId,'Workflow notice ' . $suffix]);
    $assert(count($notifyQuery->fetchAll()) === 1, 'Committed notification steps must not duplicate on later ticks.');
    $foreignNotify = $notifyDefinition; $foreignNotify['nodes'][0]['settings']['ownerId'] = $adminB->getId();
    $foreignNotifyDraft = $create($foreignNotify);
    $blocked(fn () => $callA(fn () => $serviceA->publish($foreignNotifyDraft['id'], 1)), 'Notification recipients must not cross tenant boundaries.');
    $inactiveNotify = $create($notifyDefinition);
    $callA(fn () => $serviceA->publish($inactiveNotify['id'], 1));
    $inactiveNotifyRun = $callA(fn () => $serviceA->enroll($inactiveNotify['id'], $contactA2->getId(), 'inactive-notification-recipient'));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$adminA->getId()]);
    $tick();
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$adminA->getId()]);
    $assert($callA(fn () => $history($serviceA, $inactiveNotify['id'], $inactiveNotifyRun['id']))['status'] === 'failed', 'Unavailable notification recipients must fail execution.');
    $notifyQuery->execute([$a->tenantId,$a->serviceId,'Workflow notice ' . $suffix]);
    $assert(count($notifyQuery->fetchAll()) === 1, 'Failed notification steps must not leave partial notifications.');
    $deniedNotify = $notifyDefinition; $deniedNotify['nodes'][0]['settings']['ownerId'] = $regularA->getId();
    $deniedNotifyDraft = $create($deniedNotify);
    $blocked(fn () => $callA(fn () => $serviceA->publish($deniedNotifyDraft['id'], 1)), 'Staff without Contact read access must not receive workflow notifications.');
    $assert($callA(fn () => $serviceA->enroll($notifyDraft['id'], $contactA->getId(), 'native-notification'))['id'] === $notifyRun['id'], 'Notification enrollment replay must reuse its durable run.');
    $retryNotify = $notifyDefinition; $retryNotify['nodes'][0]['maxAttempts'] = 2;
    $retryNotifyDraft = $create($retryNotify);
    $assert($callA(fn ()=>$serviceA->publish($retryNotifyDraft['id'],1))['publishedVersion']===1,'Native notifications may retry only after transaction rollback.');
    $cancelNotify = $notifyDefinition; $cancelNotify['nodes'][0]['settings']['message'] = 'Cancelled notice ' . $suffix;
    $cancelNotifyDraft = $create($cancelNotify);
    $callA(fn () => $serviceA->publish($cancelNotifyDraft['id'], 1));
    $cancelNotifyRun = $callA(fn () => $serviceA->enroll($cancelNotifyDraft['id'], $contactA->getId(), 'cancel-notification'));
    $callA(fn () => $serviceA->cancel($cancelNotifyDraft['id'], $cancelNotifyRun['id'])); $tick();
    $notifyQuery->execute([$a->tenantId,$a->serviceId,'Cancelled notice ' . $suffix]);
    $assert(count($notifyQuery->fetchAll()) === 0, 'Cancelled steps must not create notifications.');
    $salesOptions = $callA(fn () => $serviceA->workspace())['opportunityOptions'];
    $salesStages = array_values(array_filter($salesOptions['stages'], fn ($row) => $row['stage'] === 'Prospecting'));
    $assert(count($salesStages) > 0 && count($salesOptions['currencies']) > 0, 'The editor must expose tenant-configured sales stages and currencies.');
    $saleSettings = ['name' => 'Workflow sale ' . $suffix, 'pipelineId' => $salesStages[0]['pipelineId'], 'stage' => 'Prospecting', 'amount' => '1234.50', 'currency' => $salesOptions['currencies'][0], 'closeDate' => '2027-01-31', 'ownerId' => $regularA->getId()];
    $saleDefinition = ['schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'], 'entry' => 'sale', 'nodes' => [
        ['id' => 'sale', 'type' => 'action', 'action' => 'opportunity.create', 'settings' => $saleSettings, 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'], ['id' => 'end', 'type' => 'end'],
    ]];
    $saleDraft = $create($saleDefinition);
    $callA(fn () => $serviceA->publish($saleDraft['id'], 1));
    $saleRun = $callA(fn () => $serviceA->enroll($saleDraft['id'], $contactA->getId(), 'native-opportunity'));
    $tick();
    $saleQuery = $pdo->prepare('SELECT id,contact_id,assigned_user_id,pipeline_id,stage,amount,amount_currency,close_date,probability,expected_revenue FROM opportunity WHERE tenant_id=? AND service_id=? AND name=? AND deleted=0');
    $saleQuery->execute([$a->tenantId,$a->serviceId,$saleSettings['name']]);
    $sales = $saleQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($sales) === 1 && $sales[0]['contact_id'] === $contactA->getId() && $sales[0]['assigned_user_id'] === $regularA->getId(), 'Workflow sales must create native scoped Contact-linked Opportunities with the chosen owner.');
    $assert((float) $sales[0]['amount'] === 1234.5 && $sales[0]['amount_currency'] === $saleSettings['currency'] && $sales[0]['close_date'] === '2027-01-31', 'Native sales values must retain configured currency, amount and close date.');
    $assert($sales[0]['pipeline_id'] === $saleSettings['pipelineId'] && (float) $sales[0]['expected_revenue'] === round(1234.5 * (int) $sales[0]['probability'] / 100, 2), 'Native stage policies must calculate probability and expected revenue.');
    $saleEntity = $callA(fn () => $em->getEntityById('Opportunity', $sales[0]['id']));
    $assert($callA(fn () => $em->getRDBRepository('Opportunity')->getRelation($saleEntity, 'contacts')->isRelated($contactA)), 'Native Opportunity contacts relationships must be retained.');
    $saleRevenue = $pdo->prepare('SELECT COUNT(*) FROM nexa_opportunity_revenue_history WHERE tenant_id=? AND service_id=? AND opportunity_id=?');
    $saleRevenue->execute([$a->tenantId,$a->serviceId,$sales[0]['id']]);
    $assert((int) $saleRevenue->fetchColumn() === 1, 'Workflow sales must retain native revenue history.');
    $tick(); $tick();
    $saleQuery->execute([$a->tenantId,$a->serviceId,$saleSettings['name']]);
    $assert(count($saleQuery->fetchAll()) === 1, 'Committed sales actions must not duplicate on repeated processing.');
    foreach (['pipelineId' => 'missing-pipeline', 'stage' => 'Missing stage', 'currency' => 'ZZZ', 'ownerId' => $adminB->getId()] as $field => $value) {
        $invalidSale = $saleDefinition; $invalidSale['nodes'][0]['settings'][$field] = $value;
        $invalidSaleDraft = $create($invalidSale);
        $blocked(fn () => $callA(fn () => $serviceA->publish($invalidSaleDraft['id'], 1)), 'Invalid or foreign sales settings must fail publication.');
    }
    foreach (['amount' => '-1', 'closeDate' => '2027-02-30'] as $field => $value) {
        $invalidSale = $saleDefinition; $invalidSale['nodes'][0]['settings'][$field] = $value;
        $blocked(fn () => $create($invalidSale), 'Invalid financial amounts and calendar dates must fail graph validation.');
    }
    $saleEvents = $callA(fn () => $serviceA->history($saleDraft['id']))['events'];
    $saleSteps = array_values(array_filter($saleEvents, fn ($event) => $event['eventType'] === 'step.completed' && $event['nodeId'] === 'sale'));
    $assert(count($saleSteps) === 1 && $saleSteps[0]['payload']['opportunityId'] === $sales[0]['id'], 'Opportunity creation must audit its native ID atomically with advancement.');
    $assert($callA(fn () => $serviceA->enroll($saleDraft['id'], $contactA->getId(), 'native-opportunity'))['id'] === $saleRun['id'], 'Opportunity enrollment replay must reuse its durable run.');
    $archivedSale = $create($saleDefinition);
    $callA(fn () => $serviceA->publish($archivedSale['id'], 1));
    $archivedSaleRun = $callA(fn () => $serviceA->enroll($archivedSale['id'], $contactA2->getId(), 'archived-sale-pipeline'));
    $pdo->prepare('UPDATE nexa_sales_pipeline SET is_active=0 WHERE tenant_id=? AND service_id=? AND id=?')->execute([$a->tenantId,$a->serviceId,$saleSettings['pipelineId']]);
    $tick();
    $pdo->prepare('UPDATE nexa_sales_pipeline SET is_active=1 WHERE tenant_id=? AND service_id=? AND id=?')->execute([$a->tenantId,$a->serviceId,$saleSettings['pipelineId']]);
    $assert($callA(fn () => $history($serviceA, $archivedSale['id'], $archivedSaleRun['id']))['status'] === 'failed', 'Archived pipelines must fail closed during execution.');
    $saleQuery->execute([$a->tenantId,$a->serviceId,$saleSettings['name']]);
    $assert(count($saleQuery->fetchAll()) === 1, 'Invalidated sales configuration must not leave partial Opportunities.');
    $policyQuery = $pdo->prepare('SELECT required_fields_json FROM nexa_sales_stage WHERE tenant_id=? AND service_id=? AND pipeline_id=? AND name=?');
    $policyQuery->execute([$a->tenantId,$a->serviceId,$saleSettings['pipelineId'],$saleSettings['stage']]);
    $oldPolicy = $policyQuery->fetchColumn();
    $policyUpdate = $pdo->prepare('UPDATE nexa_sales_stage SET required_fields_json=? WHERE tenant_id=? AND service_id=? AND pipeline_id=? AND name=?');
    $policyUpdate->execute(['["description"]',$a->tenantId,$a->serviceId,$saleSettings['pipelineId'],$saleSettings['stage']]);
    $policySale = $create($saleDefinition);
    $blocked(fn () => $callA(fn () => $serviceA->publish($policySale['id'], 1)), 'Stages requiring unsupported input properties must fail publication rather than inventing data.');
    $policyUpdate->execute([$oldPolicy,$a->tenantId,$a->serviceId,$saleSettings['pipelineId'],$saleSettings['stage']]);
    $inactiveSale = $create($saleDefinition);
    $callA(fn () => $serviceA->publish($inactiveSale['id'], 1));
    $inactiveSaleRun = $callA(fn () => $serviceA->enroll($inactiveSale['id'], $contactA2->getId(), 'inactive-sale-owner'));
    $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$regularA->getId()]); $tick();
    $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$regularA->getId()]);
    $assert($callA(fn () => $history($serviceA, $inactiveSale['id'], $inactiveSaleRun['id']))['status'] === 'failed', 'Opportunity owners must remain active at execution.');
    $cancelSale = $saleDefinition; $cancelSale['nodes'][0]['settings']['name'] = 'Cancelled sale ' . $suffix;
    unset($cancelSale['nodes'][0]['settings']['ownerId']);
    $cancelSaleDraft = $create($cancelSale);
    $callA(fn () => $serviceA->publish($cancelSaleDraft['id'], 1));
    $cancelSaleRun = $callA(fn () => $serviceA->enroll($cancelSaleDraft['id'], $contactA->getId(), 'cancel-sale'));
    $callA(fn () => $serviceA->cancel($cancelSaleDraft['id'], $cancelSaleRun['id'])); $tick();
    $saleQuery->execute([$a->tenantId,$a->serviceId,$cancelSale['nodes'][0]['settings']['name']]);
    $assert(count($saleQuery->fetchAll()) === 0, 'Cancelled Opportunity steps must not create native sales records.');
    $salesB = $factory->createWith(\Espo\Custom\Tools\Sales\SalesWorkspaceService::class, ['user' => $adminB, 'acl' => $container->getByClass(AclManager::class)->createUserAcl($adminB)]);
    $callB(fn () => $salesB->getWorkspace());
    $foreignSalesOptions = $callB(fn () => $serviceB->workspace())['opportunityOptions'];
    $assert(count($foreignSalesOptions['stages']) > 0 && !in_array($foreignSalesOptions['stages'][0]['pipelineId'], array_column($salesOptions['stages'], 'pipelineId'), true), 'Sales option catalogues must not leak pipelines across tenants.');
    $foreignPipelineSale = $saleDefinition;
    $foreignPipelineSale['nodes'][0]['settings']['pipelineId'] = $foreignSalesOptions['stages'][0]['pipelineId'];
    $foreignPipelineDraft = $create($foreignPipelineSale);
    $blocked(fn () => $callA(fn () => $serviceA->publish($foreignPipelineDraft['id'], 1)), 'A real foreign-tenant pipeline must not be publishable.');
    $zeroSale = $saleDefinition; $zeroSale['nodes'][0]['settings']['name'] = 'Default owner sale ' . $suffix;
    $zeroSale['nodes'][0]['settings']['amount'] = '0'; unset($zeroSale['nodes'][0]['settings']['ownerId']);
    $zeroSaleDraft = $create($zeroSale);
    $callA(fn () => $serviceA->publish($zeroSaleDraft['id'], 1));
    $callA(fn () => $serviceA->enroll($zeroSaleDraft['id'], $contactA2->getId(), 'zero-value-sale')); $tick();
    $saleQuery->execute([$a->tenantId,$a->serviceId,$zeroSale['nodes'][0]['settings']['name']]);
    $zeroRows = $saleQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($zeroRows) === 1 && (float) $zeroRows[0]['amount'] === 0.0 && $zeroRows[0]['assigned_user_id'] === $adminA->getId(), 'Zero-value Opportunities must remain valid and omitted owners must use the publishing administrator.');
    $dateKey = 'workflow_date_' . $suffix;
    $dateField = $callA(fn () => $custom->saveDefinition('field', ['entityType'=>'Contact', 'fieldKey'=>$dateKey, 'label'=>'Renewal date', 'dataType'=>'date']));
    $pdo->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_date,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)')->execute([$a->tenantId,$a->serviceId,$dateField['id'],'Contact',$contactA->getId(),'2028-02-29',$adminA->getId(),$adminA->getId()]);
    $dateDefinition = $base;
    $dateDefinition['nodes'][0]['condition'] = ['field'=>'custom:' . $dateKey, 'operator'=>'on_or_before', 'value'=>'2028-02-29'];
    $dateDraft = $create($dateDefinition);
    $callA(fn () => $serviceA->publish($dateDraft['id'], 1));
    $dateRun = $callA(fn () => $serviceA->enroll($dateDraft['id'], $contactA->getId(), 'date-branch'));
    $missingDateRun = $callA(fn () => $serviceA->enroll($dateDraft['id'], $contactA2->getId(), 'missing-date-branch')); $tick();
    $assert($callA(fn () => $history($serviceA, $dateDraft['id'], $dateRun['id']))['nodeId'] === 'wait', 'Leap-year dates must match an inclusive calendar boundary.');
    $assert($callA(fn () => $history($serviceA, $dateDraft['id'], $missingDateRun['id']))['nodeId'] === 'end', 'Missing dates must take the nonmatching workflow branch.');
    foreach (['2027-02-29','2028-02-30'] as $invalid) {
        $badDate = $dateDefinition; $badDate['nodes'][0]['condition']['value'] = $invalid;
        $blocked(fn () => $create($badDate), 'Calendar workflow conditions must reject invalid dates and timestamps.');
    }
    $badDateType = $dateDefinition; $badDateType['nodes'][0]['condition']['field'] = 'firstName';
    $badDateDraft = $create($badDateType);
    $blocked(fn () => $callA(fn () => $serviceA->publish($badDateDraft['id'], 1)), 'Date operators must not be applied to text properties.');
    $assert(!in_array('custom:' . $dateKey, array_column($callB(fn () => $serviceB->workspace())['conditionFields'], 'name'), true), 'Workflow date catalogues must not cross tenant boundaries.');
    $callA(fn () => $custom->saveDefinition('propertyPreference', ['entityType'=>'Contact','fieldKey'=>$dateKey,'isEnabled'=>false]));
    $tick();
    $assert($callA(fn () => $history($serviceA, $dateDraft['id'], $dateRun['id']))['status'] === 'failed', 'Disabling a referenced date must block already-enrolled execution.');
    $blocked(fn () => $callA(fn () => $serviceA->publish($dateDraft['id'], 1)), 'Disabled date properties must not republish.');
    $timeKey='workflow_time_' . $suffix;
    $timeField=$callA(fn () => $custom->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$timeKey,'label'=>'Appointment time','dataType'=>'datetime']));
    $pdo->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_datetime,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,?,?,?)')->execute([$a->tenantId,$a->serviceId,$timeField['id'],'Contact',$contactA->getId(),'2028-02-29 09:00:00.000001',$adminA->getId(),$adminA->getId()]);
    $timeDefinition=$base;
    $timeDefinition['nodes'][0]['condition']=['field'=>'custom:' . $timeKey,'operator'=>'after','value'=>'2028-02-29T10:00:00+01:00'];
    $timeDraft=$create($timeDefinition);
    $callA(fn () => $serviceA->publish($timeDraft['id'],1));
    $timeRun=$callA(fn () => $serviceA->enroll($timeDraft['id'],$contactA->getId(),'timestamp-branch'));
    $missingTimeRun=$callA(fn () => $serviceA->enroll($timeDraft['id'],$contactA2->getId(),'missing-timestamp')); $tick();
    $assert($callA(fn () => $history($serviceA,$timeDraft['id'],$timeRun['id']))['nodeId']==='wait','Timestamp branches must normalize offsets and preserve stored microseconds.');
    $assert($callA(fn () => $history($serviceA,$timeDraft['id'],$missingTimeRun['id']))['nodeId']==='end','Missing timestamps must take the nonmatching branch.');
    foreach (['2028-02-29','2028-02-29T09:00:00','2028-02-30T09:00:00Z'] as $invalid) {
        $badTime=$timeDefinition; $badTime['nodes'][0]['condition']['operator']='equals'; $badTime['nodes'][0]['condition']['value']=$invalid;
        $badTimeDraft=$create($badTime);
        $blocked(fn () => $callA(fn () => $serviceA->publish($badTimeDraft['id'],1)),'Timestamp properties must reject ambiguous or invalid values at publication.');
    }
    $mixedDate=$timeDefinition; $mixedDate['nodes'][0]['condition']['field']='custom:' . $dateKey;
    $mixedDraft=$create($mixedDate);
    $blocked(fn () => $callA(fn () => $serviceA->publish($mixedDraft['id'],1)),'Calendar properties cannot accept timestamp comparisons.');
    $assert(!in_array('custom:' . $timeKey,array_column($callB(fn () => $serviceB->workspace())['conditionFields'],'name'),true),'Timestamp catalogues must remain tenant-scoped.');
    $blocked(fn () => $contexts->runWith($other,fn () => $serviceA->detail($timeDraft['id'])),'Timestamp workflows must remain service-scoped.');
    $waitTime=$timeDefinition;
    $waitTime['nodes'][0]['type']='wait_condition';
    $waitTime['nodes'][0]['timeoutSeconds']=3600; $waitTime['nodes'][0]['pollSeconds']=60;
    $waitTime['nodes'][0]['condition']['operator']='before';
    $waitTimeDraft=$create($waitTime);
    $callA(fn () => $serviceA->publish($waitTimeDraft['id'],1));
    $waitTimeRun=$callA(fn () => $serviceA->enroll($waitTimeDraft['id'],$contactA->getId(),'timestamp-wait')); $tick();
    $assert($callA(fn () => $history($serviceA,$waitTimeDraft['id'],$waitTimeRun['id']))['status']==='waiting','Nonmatching timestamp waits must persist their poll state.');
    $pdo->prepare('UPDATE nexa_custom_field_value SET value_datetime=? WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND entity_id=?')->execute(['2028-02-29 08:59:59.999999',$a->tenantId,$a->serviceId,$timeField['id'],$contactA->getId()]);
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$waitTimeRun['id'],$a->tenantId,$a->serviceId]); $tick();
    $assert($callA(fn () => $history($serviceA,$waitTimeDraft['id'],$waitTimeRun['id']))['nodeId']==='wait','Timestamp waits must reread changed values before advancing.');
    $callA(fn () => $custom->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$timeKey,'isEnabled'=>false]));
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$timeRun['id'],$a->tenantId,$a->serviceId]); $tick();
    $assert($callA(fn () => $history($serviceA,$timeDraft['id'],$timeRun['id']))['status']==='failed','Disabling timestamp properties must block enrolled execution.');
    $blocked(fn () => $callA(fn () => $serviceA->publish($timeDraft['id'],1)),'Disabled timestamp properties must not republish.');
    $relativeKey='relative_date_' . $suffix;
    $relativeField=$callA(fn () => $custom->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$relativeKey,'label'=>'Relative renewal','dataType'=>'date']));
    $pdo->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_date,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,DATE_ADD(UTC_DATE(),INTERVAL 2 DAY),?,?)')->execute([$a->tenantId,$a->serviceId,$relativeField['id'],'Contact',$contactA->getId(),$adminA->getId(),$adminA->getId()]);
    $relativeDefinition=$base;
    $relativeDefinition['nodes'][0]['condition']=['field'=>'custom:' . $relativeKey,'operator'=>'within_next_days','value'=>'3'];
    $relativeDraft=$create($relativeDefinition);
    $callA(fn () => $serviceA->publish($relativeDraft['id'],1));
    $relativeRun=$callA(fn () => $serviceA->enroll($relativeDraft['id'],$contactA->getId(),'relative-date-branch'));
    $relativeMissing=$callA(fn () => $serviceA->enroll($relativeDraft['id'],$contactA2->getId(),'relative-missing')); $tick();
    $assert($callA(fn () => $history($serviceA,$relativeDraft['id'],$relativeRun['id']))['nodeId']==='wait','Relative date branches must match future dates within the UTC window.');
    $assert($callA(fn () => $history($serviceA,$relativeDraft['id'],$relativeMissing['id']))['nodeId']==='end','Missing relative dates must take the nonmatching branch.');
    $relativeText=$relativeDefinition; $relativeText['nodes'][0]['condition']['field']='firstName';
    $relativeTextDraft=$create($relativeText);
    $blocked(fn () => $callA(fn () => $serviceA->publish($relativeTextDraft['id'],1)),'Relative operators must not be allowed on text fields.');
    $relativeWait=$relativeDefinition; $relativeWait['nodes'][0]['type']='wait_condition'; $relativeWait['nodes'][0]['timeoutSeconds']=3600; $relativeWait['nodes'][0]['pollSeconds']=60;
    $relativeWait['nodes'][0]['condition']['operator']='within_last_days';
    $relativeWaitDraft=$create($relativeWait); $callA(fn () => $serviceA->publish($relativeWaitDraft['id'],1));
    $relativeWaitRun=$callA(fn () => $serviceA->enroll($relativeWaitDraft['id'],$contactA->getId(),'relative-date-wait')); $tick();
    $assert($callA(fn () => $history($serviceA,$relativeWaitDraft['id'],$relativeWaitRun['id']))['status']==='waiting','Relative waits must not match future dates as past activity.');
    $pdo->prepare('UPDATE nexa_custom_field_value SET value_date=DATE_SUB(UTC_DATE(),INTERVAL 1 DAY) WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND entity_id=?')->execute([$a->tenantId,$a->serviceId,$relativeField['id'],$contactA->getId()]);
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$relativeWaitRun['id'],$a->tenantId,$a->serviceId]); $tick();
    $assert($callA(fn () => $history($serviceA,$relativeWaitDraft['id'],$relativeWaitRun['id']))['nodeId']==='wait','Relative waits must evaluate current scoped values on each poll.');
    $assert(!in_array('custom:' . $relativeKey,array_column($callB(fn () => $serviceB->workspace())['conditionFields'],'name'),true),'Relative condition catalogues must remain tenant-scoped.');
    $callA(fn () => $custom->saveDefinition('propertyPreference',['entityType'=>'Contact','fieldKey'=>$timeKey,'isEnabled'=>true]));
    $pdo->prepare('UPDATE nexa_custom_field_value SET value_datetime=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY) WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND entity_id=?')->execute([$a->tenantId,$a->serviceId,$timeField['id'],$contactA->getId()]);
    $relativeGroup=$base;
    $relativeGroup['nodes'][0]['condition']=['matchMode'=>'all','conditions'=>[['field'=>'custom:' . $relativeKey,'operator'=>'within_last_days','value'=>'3'],['field'=>'custom:' . $timeKey,'operator'=>'within_last_days','value'=>'2']]];
    $relativeGroupDraft=$create($relativeGroup); $callA(fn () => $serviceA->publish($relativeGroupDraft['id'],1));
    $relativeGroupRun=$callA(fn () => $serviceA->enroll($relativeGroupDraft['id'],$contactA->getId(),'relative-date-group')); $tick();
    $assert($callA(fn () => $history($serviceA,$relativeGroupDraft['id'],$relativeGroupRun['id']))['nodeId']==='wait','Grouped relative calendar and timestamp rules must use scoped existing storage.');
    $callA(fn () => $custom->archive('field',$relativeField['id']));
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$relativeGroupRun['id'],$a->tenantId,$a->serviceId]); $tick();
    $assert($callA(fn () => $history($serviceA,$relativeGroupDraft['id'],$relativeGroupRun['id']))['status']==='failed','Archived relative properties must block existing execution.');
    $filteredGraph = $automatic; $filteredGraph['trigger']['pageUrl'] = 'https://example.test/Pricing';
    $filteredDraft = $create($filteredGraph, 'Specific page enrollment');
    $callA(fn () => $serviceA->publish($filteredDraft['id'], 1));
    foreach (['https://example.test/pricing', 'https://example.test/Pricing?offer=1', 'https://example.test/Pricing'] as $index => $pageUrl) {
        $input = $eventInput('filtered-page-' . $index, $autoContact->getId()); $input->pageUrl = $pageUrl;
        $callA(fn () => $events->ingest($input));
    }
    $scanA(); $scanA();
    $filteredHistory = $callA(fn () => $serviceA->history($filteredDraft['id']));
    $assert(count($filteredHistory['runs']) === 1, 'Exact page filters must enroll only matching page events.');
    $receiptCheck = $pdo->prepare('SELECT COUNT(*) FROM nexa_workflow_trigger_receipt WHERE workflow_id=? AND tenant_id=? AND service_id=?');
    $receiptCheck->execute([$filteredDraft['id'], $a->tenantId, $a->serviceId]);
    $assert((int) $receiptCheck->fetchColumn() === 1, 'Nonmatching URLs must not consume enrollment receipts.');
    $callA(fn () => $serviceA->control($filteredDraft['id'], 'archived'));
    $countContact = $callA(fn () => $em->createEntity('Contact', ['firstName'=>'Count conditions']));
    $emptyCountContact = $callA(fn () => $em->createEntity('Contact', ['firstName'=>'No counted events']));
    foreach (['one','two'] as $key) $callA(fn () => $events->ingest($eventInput('count-' . $key, $countContact->getId())));
    foreach (['expired'=>'DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 8 DAY)', 'future'=>'DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 DAY)'] as $key => $timeExpression) {
        $countEvent=$callA(fn () => $events->ingest($eventInput('count-' . $key,$countContact->getId())));
        $pdo->prepare("UPDATE nexa_timeline_event SET source_occurred_at=$timeExpression WHERE tenant_id=? AND service_id=? AND source_entity_type='BehaviorEvent' AND source_entity_id=?")->execute([$a->tenantId,$a->serviceId,$countEvent['id']]);
    }
    $assert($callA(fn () => $events->ingest($eventInput('count-one', $countContact->getId())))['duplicate'], 'Canonical event replay must not add another counted event.');
    foreach ([['count_at_least',2,true],['count_at_least',3,false],['count_equals',2,true],['count_at_most',1,false],['count_at_most',2,true]] as $index => [$operator,$threshold,$expected]) {
        $countGraph=$base;$countGraph['nodes'][0]['condition']=['field'=>'behavior:page.viewed','operator'=>$operator,'value'=>'7','count'=>$threshold];
        $countDraft=$create($countGraph,'Behavior count ' . $index);
        $callA(fn () => $serviceA->publish($countDraft['id'],1));
        $countRun=$callA(fn () => $serviceA->enroll($countDraft['id'],$countContact->getId(),'behavior-count-' . $index));
        $tick();
        $assert($callA(fn () => $history($serviceA,$countDraft['id'],$countRun['id']))['nodeId'] === ($expected?'wait':'end'), 'Count comparisons must use the distinct scoped event total.');
        $callA(fn () => $serviceA->control($countDraft['id'],'archived'));
    }
    $zeroGraph=$base;$zeroGraph['nodes'][0]['condition']=['field'=>'behavior:page.viewed','operator'=>'count_equals','value'=>'7','count'=>0];
    $zeroDraft=$create($zeroGraph,'Zero behavior count');$callA(fn () => $serviceA->publish($zeroDraft['id'],1));
    $zeroRun=$callA(fn () => $serviceA->enroll($zeroDraft['id'],$emptyCountContact->getId(),'zero-event-count'));
    $tick();$assert($callA(fn () => $history($serviceA,$zeroDraft['id'],$zeroRun['id']))['nodeId']==='wait','Missing events must count as zero, not as another Contact history.');
    $pageGraph=$base; $pageGraph['nodes'][0]['condition']=['field'=>'behavior:page.viewed','operator'=>'count_equals','value'=>'30','count'=>1,'pageUrl'=>'https://example.test/Pricing'];
    $pageDraft=$create($pageGraph); $callA(fn ()=>$serviceA->publish($pageDraft['id'],1));
    $pageRun=$callA(fn ()=>$serviceA->enroll($pageDraft['id'],$autoContact->getId(),'exact-page-count')); $tick();
    $assert($callA(fn ()=>$history($serviceA,$pageDraft['id'],$pageRun['id']))['nodeId']==='wait','Branch page counts must exclude case and query variants.');
    $pageGraph['nodes'][0]['condition']=['field'=>'behavior:page.viewed','operator'=>'within_days','value'=>'30','pageUrl'=>'https://example.test/Pricing?offer=2'];
    $noPageDraft=$create($pageGraph); $callA(fn ()=>$serviceA->publish($noPageDraft['id'],1));
    $noPageRun=$callA(fn ()=>$serviceA->enroll($noPageDraft['id'],$autoContact->getId(),'missing-page')); $tick();
    $assert($callA(fn ()=>$history($serviceA,$noPageDraft['id'],$noPageRun['id']))['nodeId']==='end','Exact-page presence must not match a different query.');
    $pageGraph['nodes'][0]['type']='wait_condition'; $pageGraph['nodes'][0]['timeoutSeconds']=3600; $pageGraph['nodes'][0]['pollSeconds']=60;
    $pageWaitDraft=$create($pageGraph); $callA(fn ()=>$serviceA->publish($pageWaitDraft['id'],1));
    $pageWaitRun=$callA(fn ()=>$serviceA->enroll($pageWaitDraft['id'],$autoContact->getId(),'wait-for-page')); $tick();
    $assert($callA(fn ()=>$history($serviceA,$pageWaitDraft['id'],$pageWaitRun['id']))['status']==='waiting','Page waits must persist until a matching page event arrives.');
    $newPage=$eventInput('awaited-page',$autoContact->getId()); $newPage->pageUrl='https://example.test/Pricing?offer=2'; $callA(fn ()=>$events->ingest($newPage));
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$pageWaitRun['id'],$a->tenantId,$a->serviceId]); $tick();
    $assert($callA(fn ()=>$history($serviceA,$pageWaitDraft['id'],$pageWaitRun['id']))['nodeId']==='wait','Page waits must advance after a matching scoped event.');
    $blocked(fn ()=>$callB(fn ()=>$serviceB->detail($pageDraft['id'])),'Page-filter workflows must remain tenant-scoped.');
    // Isolate native enrollment acceptance from the earlier behavior fixtures.
    $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=?")->execute([$a->tenantId,$a->serviceId]);
    $endOnly=['schemaVersion'=>1,'trigger'=>['event'=>'manual','reenrollment'=>'never'],'entry'=>'end','nodes'=>[['id'=>'end','type'=>'end']]];
    $nativeDrafts=[];
    foreach (['contact.created','contact.updated','contact.field_changed','lifecycle.changed','score.reached'] as $event) {
        $graph=$endOnly;$graph['trigger']['event']=$event;
        if($event==='contact.field_changed')$graph['trigger']['field']='leadStatus';
        if($event==='score.reached')$graph['trigger']['threshold']=50;
        $nativeDrafts[$event]=$create($graph);$callA(fn ()=>$serviceA->publish($nativeDrafts[$event]['id'],1));
    }
    $nativeContact=$callA(fn ()=>$em->createEntity('Contact',['firstName'=>'Native','lastName'=>'Enrollment','leadScore'=>10,'leadStatus'=>'New']));
    $callA(fn ()=>$worker->processAutomatic());
    $nativeHistory=$callA(fn ()=>$serviceA->history($nativeDrafts['contact.created']['id']));
    $assert(count($nativeHistory['runs'])===1,'A real native Contact create must enroll through the existing outbox.');
    $callA(fn ()=>$worker->processAutomatic());
    $assert(count($callA(fn ()=>$serviceA->history($nativeDrafts['contact.created']['id']))['runs'])===1,'Native outbox events must be replay safe.');
    $callA(function () use ($em,$nativeContact) {$record=$em->getEntityById('Contact',$nativeContact->getId());$record->set(['leadStatus'=>'Connected','leadScore'=>60,'lifecycleStage'=>'Customer']);$em->saveEntity($record);});
    $callA(fn ()=>$worker->processAutomatic());
    foreach (['contact.updated','contact.field_changed','lifecycle.changed','score.reached'] as $event) $assert(count($callA(fn ()=>$serviceA->history($nativeDrafts[$event]['id']))['runs'])===1,'Native event enrollment must execute for ' . $event);
    $callA(function () use ($em,$nativeContact) {$record=$em->getEntityById('Contact',$nativeContact->getId());$record->set('leadScore',70);$em->saveEntity($record);});
    $scoreEvents=$callA(fn ()=>$factory->create(\Espo\Custom\Tools\Automation\WorkflowTriggerSource::class)->events(['event'=>'score.reached','threshold'=>50],'1000-01-01','unconsumed-score-test'));
    $ownScoreEvents=array_filter($scoreEvents,fn ($event)=>$event['contact_id']===$nativeContact->getId());
    $assert(count($ownScoreEvents)===1,'Remaining above the score threshold must not create another crossing.');
    foreach ([40,80] as $score) $callA(function () use ($em,$nativeContact,$score) {$record=$em->getEntityById('Contact',$nativeContact->getId());$record->set('leadScore',$score);$em->saveEntity($record);});
    $scoreEvents=$callA(fn ()=>$factory->create(\Espo\Custom\Tools\Automation\WorkflowTriggerSource::class)->events(['event'=>'score.reached','threshold'=>50],'1000-01-01','unconsumed-score-test'));
    $assert(count(array_filter($scoreEvents,fn ($event)=>$event['contact_id']===$nativeContact->getId()))===2,'A new crossing after falling below the threshold must remain discoverable.');
    $callB(fn ()=>$em->createEntity('Contact',['firstName'=>'Foreign','lastName'=>'Outbox']));
    $callA(fn ()=>$worker->processAutomatic());
    $assert(count($callA(fn ()=>$serviceA->history($nativeDrafts['contact.created']['id']))['runs'])===1,'Foreign native outbox events must not enroll into tenant A.');
    $wrongScope=$contexts->runWith($other,fn ()=>$factory->create(\Espo\Custom\Tools\Automation\WorkflowTriggerSource::class)->events(['event'=>'contact.created'], '1000-01-01',$nativeDrafts['contact.created']['id']));
    $assert($wrongScope===[],'Native event discovery must remain service-scoped.');
    $badField=$endOnly;$badField['trigger']=['event'=>'contact.field_changed','reenrollment'=>'never','field'=>'unavailableField'];$badFieldDraft=$create($badField);
    $blocked(fn ()=>$callA(fn ()=>$serviceA->publish($badFieldDraft['id'],1)),'Unavailable enrollment fields must not publish.');
    $newList=$callA(fn ()=>$segmentsA->save((object) ['name'=>'Enrollment list '.$suffix,'type'=>'static']));
    $listDrafts=[];
    foreach (['segment.entered','segment.exited'] as $event) {$graph=$endOnly;$graph['trigger']=['event'=>$event,'reenrollment'=>'never','segmentId'=>$newList['id']];$listDrafts[$event]=$create($graph);$callA(fn ()=>$serviceA->publish($listDrafts[$event]['id'],1));}
    $callA(fn ()=>$segmentsA->changeMembership($newList['id'],$nativeContact->getId(),true));$callA(fn ()=>$worker->processAutomatic());
    $assert(count($callA(fn ()=>$serviceA->history($listDrafts['segment.entered']['id']))['runs'])===1,'Native segment entry history must enroll a Contact.');
    $callA(fn ()=>$segmentsA->changeMembership($newList['id'],$nativeContact->getId(),false));$callA(fn ()=>$worker->processAutomatic());
    $assert(count($callA(fn ()=>$serviceA->history($listDrafts['segment.exited']['id']))['runs'])===1,'Native segment exit history must enroll a Contact.');
    foreach (['opened','clicked'] as $kind) {
        $graph=$endOnly;$graph['trigger']['event']='email.'.$kind;$emailDraft=$create($graph);$callA(fn ()=>$serviceA->publish($emailDraft['id'],1));
        $deliveryId=$pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare("INSERT INTO nexa_marketing_delivery_event (id,tenant_id,service_id,provider_key,provider_event_id,event_type,occurred_at) VALUES (?,?,?,'postal',?,?,UTC_TIMESTAMP(6))")->execute([$deliveryId,$a->tenantId,$a->serviceId,'workflow-'.$deliveryId,$kind]);
        $projection=$pdo->prepare("INSERT INTO nexa_timeline_event (id,tenant_id,service_id,contact_id,event_type,source_entity_type,source_entity_id,source_occurred_at,actor_type,visibility,summary) VALUES (UUID(),?,?,?,?,'MarketingDeliveryEvent',?,UTC_TIMESTAMP(6),'system','internal','Workflow acceptance')");
        $projection->execute([$a->tenantId,$a->serviceId,$nativeContact->getId(),'email.'.$kind,$deliveryId]);
        $projection->execute([$a->tenantId,$a->serviceId,$nativeContact->getId(),'email.'.$kind,'missing-provider-evidence-'.$kind]);
        $callA(fn ()=>$worker->processAutomatic());$callA(fn ()=>$worker->processAutomatic());
        $assert(count($callA(fn ()=>$serviceA->history($emailDraft['id']))['runs'])===1,'Email '.$kind.' enrollment must require matching delivery evidence and remain replay safe.');
        $receipts=$pdo->prepare('SELECT event_id FROM nexa_workflow_trigger_receipt WHERE workflow_id=? AND tenant_id=? AND service_id=?');$receipts->execute([$emailDraft['id'],$a->tenantId,$a->serviceId]);
        $assert($receipts->fetchAll(PDO::FETCH_COLUMN)===[$deliveryId],'An unsupported timeline label must not consume an email enrollment receipt.');
    }
    $shared=$endOnly;$shared['layout']=['end'=>['x'=>123,'y'=>456]];$sharedDraft=$create($shared);
    $assert($callA(fn ()=>$serviceA->detail($sharedDraft['id']))['definition']['layout']['end']===['x'=>123,'y'=>456],'Canvas positions must persist in the scoped workflow draft, not only local storage.');
    $callA(fn ()=>$serviceA->publish($sharedDraft['id'],1));$shared['layout']['end']['x']=900;
    $callA(fn ()=>$serviceA->save((object) ['name'=>'Shared canvas','definition'=>$asObject($shared),'expectedRevision'=>1],$sharedDraft['id']));
    $assert($callA(fn ()=>$serviceA->publish($sharedDraft['id'],2))['publishedVersion']===1,'Moving canvas nodes must not republish a different execution version.');
    $absolute=$endOnly;$absolute['entry']='absolute';$future=(new DateTimeImmutable('+1 day',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    array_unshift($absolute['nodes'],['id'=>'absolute','type'=>'wait_until','at'=>$future,'next'=>'end']);
    $absoluteDraft=$create($absolute);$callA(fn ()=>$serviceA->publish($absoluteDraft['id'],1));$absoluteRun=$callA(fn ()=>$serviceA->enroll($absoluteDraft['id'],$nativeContact->getId(),'absolute-wait-test'));
    $tick();$absoluteHistory=$callA(fn ()=>$history($serviceA,$absoluteDraft['id'],$absoluteRun['id']));
    $assert($absoluteHistory['status']==='waiting' && $absoluteHistory['nodeId']==='end','Absolute waits must persist their UTC due date without sleeping.');
    $callA(fn ()=>$serviceA->cancel($absoluteDraft['id'],$absoluteRun['id']));$tick();
    $assert($callA(fn ()=>$history($serviceA,$absoluteDraft['id'],$absoluteRun['id']))['status']==='cancelled','Absolute waits must remain cancellable.');
    $intakeGraph=$endOnly;$intakeGraph['trigger']['event']='api.event';$intakeDraft=$create($intakeGraph);$callA(fn()=>$serviceA->publish($intakeDraft['id'],1));
    $intakeApi=$apiClass('PostEvent');$intakeBody=(object)['contactId'=>$nativeContact->getId(),'requestKey'=>'native-api-intake-acceptance'];
    $intake=json_decode((string)$callA(fn()=>$intakeApi->process($request($intakeBody,['id'=>$intakeDraft['id']])))->getBody(),true,64,JSON_THROW_ON_ERROR);
    $intakeReplay=json_decode((string)$callA(fn()=>$intakeApi->process($request($intakeBody,['id'=>$intakeDraft['id']])))->getBody(),true,64,JSON_THROW_ON_ERROR);
    $assert($intake['id']===$intakeReplay['id'],'The native API action must preserve intentional-event replay identity.');
    $blocked(fn()=>$callA(fn()=>$intakeApi->process($request((object)['contactId'=>$nativeContact->getId(),'requestKey'=>'another-intake-request','tenantId'=>$b->tenantId],['id'=>$intakeDraft['id']]))),'API intake must reject client-controlled scope fields.');
    $blocked(fn()=>$callB(fn()=>$intakeApi->process($request($intakeBody,['id'=>$intakeDraft['id']]))),'The native intake action must not accept a different tenant context.');
    $stageGraph=$endOnly;$stageGraph['trigger']['event']='opportunity.stage_changed';$stageDraft=$create($stageGraph);$callA(fn()=>$serviceA->publish($stageDraft['id'],1));
    $stageRecord=$callA(fn()=>$em->getEntityById('Opportunity',$sales[0]['id']));
    $stageQuery=$pdo->prepare('SELECT name FROM nexa_sales_stage WHERE pipeline_id=? AND tenant_id=? AND service_id=? AND is_active=1 AND name<>? ORDER BY position LIMIT 1');$stageQuery->execute([$stageRecord->get('pipelineId'),$a->tenantId,$a->serviceId,$stageRecord->get('stage')]);$nextStage=$stageQuery->fetchColumn();
    $assert(is_string($nextStage),'Stage enrollment acceptance requires another configured native stage.');
    $callA(function()use($em,$stageRecord,$nextStage){$stageRecord->set('stage',$nextStage);$em->saveEntity($stageRecord);});
    $activityGraph=$endOnly;$activityGraph['trigger']['event']='activity.completed';$activityDraft=$create($activityGraph);$callA(fn()=>$serviceA->publish($activityDraft['id'],1));
    $completedTask=$callA(fn()=>$em->createEntity('Task',['name'=>'Workflow completed activity','parentType'=>'Contact','parentId'=>$nativeContact->getId(),'status'=>'Not Started']));
    $callA(function()use($em,$completedTask){$completedTask->set('status','Completed');$em->saveEntity($completedTask);});
    $enrollmentKey='enrollment_date_'.$suffix;$enrollmentField=$callA(fn()=>$custom->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$enrollmentKey,'label'=>'Enrollment date','dataType'=>'date']));
    $dateGraph=$endOnly;$dateGraph['trigger']=['event'=>'date.reached','reenrollment'=>'never','field'=>'custom:'.$enrollmentKey];$dateDraft=$create($dateGraph);$callA(fn()=>$serviceA->publish($dateDraft['id'],1));
    $pdo->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_date,created_by_id,updated_by_id) VALUES (UUID(),?,?,?,?,?,UTC_DATE(),?,?)')->execute([$a->tenantId,$a->serviceId,$enrollmentField['id'],'Contact',$contactA->getId(),$adminA->getId(),$adminA->getId()]);
    for($scan=0;$scan<4;$scan++)$callA(fn()=>$worker->processAutomatic());
    $assert(count($callA(fn()=>$serviceA->history($activityDraft['id']))['runs'])===1,'A completed native activity must enroll its associated Contact exactly once.');
    $assert(count($callA(fn()=>$serviceA->history($stageDraft['id']))['runs'])===1,'Native Opportunity stage changes must enroll their associated Contact exactly once.');
    $assert(count($callA(fn()=>$serviceA->history($dateDraft['id']))['runs'])===1,'A reached tenant custom date must enroll exactly once.');
    $callA(fn()=>$worker->processAutomatic());
    $assert(count($callA(fn()=>$serviceA->history($dateDraft['id']))['runs'])===1,'Date polling must retain stable deduplicated source identity.');
    $sequenceContact=$callA(fn()=>$em->createEntity('Contact',['firstName'=>'Sequence','lastName'=>'Acceptance']));
    $addSequenceEvent=static function(string $kind,int $seconds)use($pdo,$a,$sequenceContact):void {
        $event=$pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare("INSERT INTO nexa_marketing_delivery_event (id,tenant_id,service_id,provider_key,provider_event_id,event_type,occurred_at) VALUES (?,?,?,'postal',?,?,DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND))")->execute([$event,$a->tenantId,$a->serviceId,'sequence-'.$event,$kind,$seconds]);
        $pdo->prepare("INSERT INTO nexa_timeline_event (id,tenant_id,service_id,contact_id,event_type,source_entity_type,source_entity_id,source_occurred_at,actor_type,visibility,summary) VALUES (UUID(),?,?,?,?,'MarketingDeliveryEvent',?,DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND),'system','internal','Sequence acceptance')")->execute([$a->tenantId,$a->serviceId,$sequenceContact->getId(),'email.'.$kind,$event,$seconds]);
    };
    $addSequenceEvent('clicked',120);$addSequenceEvent('opened',60);
    $sequence=$base;$sequence['nodes'][0]['condition']=['matchMode'=>'sequence','conditions'=>[['field'=>'behavior:email.opened','operator'=>'within_days','value'=>'30'],['field'=>'behavior:email.clicked','operator'=>'within_days','value'=>'30']]];
    $reversed=$create($sequence);$callA(fn()=>$serviceA->publish($reversed['id'],1));$reversedRun=$callA(fn()=>$serviceA->enroll($reversed['id'],$sequenceContact->getId(),'sequence-reversed'));
    $tick();$assert($callA(fn()=>$history($serviceA,$reversed['id'],$reversedRun['id']))['nodeId']==='end','Events in the wrong order must not satisfy an ordered journey.');
    $addSequenceEvent('clicked',10);
    $ordered=$create($sequence);$callA(fn()=>$serviceA->publish($ordered['id'],1));$orderedRun=$callA(fn()=>$serviceA->enroll($ordered['id'],$sequenceContact->getId(),'sequence-ordered'));
    $tick();$assert($callA(fn()=>$history($serviceA,$ordered['id'],$orderedRun['id']))['nodeId']==='wait','Chronologically ordered source-backed events must satisfy the journey.');
    $campaign=$callA(fn()=>$em->createEntity('Campaign',['name'=>'Workflow behavior campaign '.$suffix]));
    $mass=$callA(fn()=>$em->createEntity('MassEmail',['name'=>'Workflow behavior source','campaignId'=>$campaign->getId()]));$profileId=$pdo->query('SELECT UUID()')->fetchColumn();
    $pdo->prepare('INSERT INTO nexa_marketing_email_profile (id,tenant_id,service_id,mass_email_id) VALUES (?,?,?,?)')->execute([$profileId,$a->tenantId,$a->serviceId,$mass->getId()]);
    $campaignGraph=$base;$campaignGraph['nodes'][0]['condition']=['field'=>'behavior:email.clicked','operator'=>'within_days','value'=>'30','campaignId'=>$campaign->getId()];
    $campaignDraft=$create($campaignGraph);$callA(fn()=>$serviceA->publish($campaignDraft['id'],1));$campaignRun=$callA(fn()=>$serviceA->enroll($campaignDraft['id'],$sequenceContact->getId(),'campaign-no-evidence'));$tick();
    $assert($callA(fn()=>$history($serviceA,$campaignDraft['id'],$campaignRun['id']))['nodeId']==='end','Unrelated email clicks must not satisfy a campaign-specific condition.');
    $pdo->prepare("UPDATE nexa_marketing_delivery_event SET profile_id=? WHERE tenant_id=? AND service_id=? AND event_type='clicked' AND provider_event_id LIKE 'sequence-%'")->execute([$profileId,$a->tenantId,$a->serviceId]);
    $campaignMatch=$create($campaignGraph);$callA(fn()=>$serviceA->publish($campaignMatch['id'],1));$campaignMatchRun=$callA(fn()=>$serviceA->enroll($campaignMatch['id'],$sequenceContact->getId(),'campaign-with-evidence'));$tick();
    $assert($callA(fn()=>$history($serviceA,$campaignMatch['id'],$campaignMatchRun['id']))['nodeId']==='wait','A matching native Campaign delivery source must satisfy its scoped condition.');
    $linkedAccount=$callA(fn()=>$em->createEntity('Account',['name'=>'Workflow associated Account','industry'=>'Technology']));
    $associatedContact=$callA(fn()=>$em->createEntity('Contact',['firstName'=>'Associated','lastName'=>'Acceptance','accountId'=>$linkedAccount->getId()]));
    $associated=$base;$associated['nodes'][0]['condition']=['field'=>'account:industry','operator'=>'equals','value'=>'Technology'];
    $associatedDraft=$create($associated);$callA(fn()=>$serviceA->publish($associatedDraft['id'],1));$associatedRun=$callA(fn()=>$serviceA->enroll($associatedDraft['id'],$associatedContact->getId(),'associated-account-state'));
    $tick();$assert($callA(fn()=>$history($serviceA,$associatedDraft['id'],$associatedRun['id']))['nodeId']==='wait','Primary Company conditions must use the current native related record.');
    $updateAccount=$create($nativeAction('account.update',['field'=>'description','value'=>'Updated through scoped workflow']));$callA(fn()=>$serviceA->publish($updateAccount['id'],1));$callA(fn()=>$serviceA->enroll($updateAccount['id'],$associatedContact->getId(),'update-related-account'));$tick();
    $assert($callA(fn()=>$em->getEntityById('Account',$linkedAccount->getId())->get('description'))==='Updated through scoped workflow','Associated Company updates must use native validation and persistence.');
    $tagDraft=$create($nativeAction('tags.add',['tag'=>'Workflow acceptance']));$callA(fn()=>$serviceA->publish($tagDraft['id'],1));$callA(fn()=>$serviceA->enroll($tagDraft['id'],$associatedContact->getId(),'native-tag-add'));$tick();$tick();
    $assert(in_array('Workflow acceptance',$callA(fn()=>$em->getEntityById('Contact',$associatedContact->getId())->get('tags')),true),'Tags must persist through the native Contact property service.');
    $removeTag=$create($nativeAction('tags.remove',['tag'=>'Workflow acceptance']));$callA(fn()=>$serviceA->publish($removeTag['id'],1));$callA(fn()=>$serviceA->enroll($removeTag['id'],$associatedContact->getId(),'native-tag-remove'));$tick();
    $assert(!in_array('Workflow acceptance',$callA(fn()=>$em->getEntityById('Contact',$associatedContact->getId())->get('tags')),true),'Native tag removal must update the same scoped record.');
    $team=$callA(fn()=>$em->createEntity('Team',['name'=>'Workflow notification team '.$suffix]));$callA(fn()=>$em->getRelation($team,'users')->relate($adminA));
    $teamDraft=$create($nativeAction('team.notify',['teamId'=>$team->getId(),'message'=>'Team workflow notice '.$suffix]));$callA(fn()=>$serviceA->publish($teamDraft['id'],1));$callA(fn()=>$serviceA->enroll($teamDraft['id'],$associatedContact->getId(),'native-team-notification'));$tick();$tick();
    $teamNotifications=$pdo->prepare('SELECT COUNT(*) FROM notification WHERE tenant_id=? AND service_id=? AND user_id=? AND message=?');$teamNotifications->execute([$a->tenantId,$a->serviceId,$adminA->getId(),'Team workflow notice '.$suffix]);
    $assert((int)$teamNotifications->fetchColumn()===1,'Team notifications must respect native membership and remain replay safe.');
    // Real native queue records, but no external requests in a rollback-only fixture.
    $hook=$callA(fn()=>$em->createEntity('Webhook',['url'=>'https://example.com/nexa-hook','isActive'=>true,'event'=>'Contact.update','secretKey'=>str_repeat('s',32)]));
    $webhooks=$factory->create(\Espo\Custom\Tools\Automation\WorkflowWebhookService::class);
    $hookGraph=$endOnly;$hookGraph['entry']='hook';
    array_unshift($hookGraph['nodes'],['id'=>'hook','type'=>'action','action'=>'webhook.send','settings'=>['webhookId'=>$hook->getId()],'maxAttempts'=>2,'retrySeconds'=>60,'next'=>'end']);
    $hookDraft=$create($hookGraph);$callA(fn()=>$serviceA->publish($hookDraft['id'],1));
    $hookRun=$callA(fn()=>$serviceA->enroll($hookDraft['id'],$nativeContact->getId(),'hook-delivery-one'));
    $tick();$effects=$callA(fn()=>$serviceA->history($hookDraft['id']))['webhookEffects'];
    $assert(count($effects)===1 && $effects[0]['status']==='pending','A webhook step must stage exactly one durable native delivery.');
    $effectId=$effects[0]['id'];
    $assert($callA(fn()=>$history($serviceA,$hookDraft['id'],$hookRun['id']))['nodeId']==='hook','The workflow must not advance before confirmed delivery.');
    $claim=$callA(fn()=>$webhooks->claim($effectId));
    $assert(is_array($claim) && $claim['id']===$effectId,'The scoped worker must claim a native queue snapshot.');
    $assert($callA(fn()=>$webhooks->claim($effectId))===null,'A live lease must prevent a second submission.');
    $assert($callB(fn()=>$webhooks->claim($effectId))===null,'Another tenant must not claim webhook effects.');
    $callA(fn()=>$webhooks->finalize($effectId,$claim['token'],['outcome'=>'not_sent','code'=>'connection_unavailable','status'=>null]));
    $assert($callA(fn()=>$serviceA->history($hookDraft['id']))['webhookEffects'][0]['status']==='pending','Known pre-submission failure may schedule a bounded retry.');
    $pdo->prepare('UPDATE nexa_workflow_webhook_effect SET due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=?')->execute([$effectId]);
    $claim2=$callA(fn()=>$webhooks->claim($effectId));
    $callA(fn()=>$webhooks->finalize($effectId,$claim['token'],['outcome'=>'success','code'=>null,'status'=>200]));
    $assert($callA(fn()=>$serviceA->history($hookDraft['id']))['webhookEffects'][0]['status']==='sending','A stale worker token must not overwrite a later lease.');
    $callA(fn()=>$webhooks->finalize($effectId,$claim2['token'],['outcome'=>'uncertain','code'=>'submission_uncertain','status'=>null]));
    $assert($callA(fn()=>$webhooks->claim($effectId))===null,'Uncertain submission must never be blindly retried.');
    $blocked(fn()=>$callB(fn()=>$serviceB->resolveWebhook($hookDraft['id'],$hookRun['id'],'success','Confirmed by endpoint audit')),'Another tenant must not review a delivery.');
    $callA(fn()=>$serviceA->resolveWebhook($hookDraft['id'],$hookRun['id'],'success','Confirmed by endpoint audit'));
    $pdo->prepare('UPDATE nexa_workflow_run SET due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=?')->execute([$hookRun['id']]);
    $tick();$tick();
    $assert($callA(fn()=>$history($serviceA,$hookDraft['id'],$hookRun['id']))['status']==='completed','Evidence-confirmed delivery must resume and finish without another send.');
    $attempts=$pdo->prepare('SELECT COUNT(*) FROM nexa_workflow_webhook_attempt WHERE effect_id=?');$attempts->execute([$effectId]);
    $assert((int)$attempts->fetchColumn()===2,'Delivery attempt evidence must retain retries without replay duplication.');
    $cancelHook=$create($hookGraph);$callA(fn()=>$serviceA->publish($cancelHook['id'],1));$cancelRun=$callA(fn()=>$serviceA->enroll($cancelHook['id'],$nativeContact->getId(),'hook-cancel-one'));$tick();
    $callA(fn()=>$serviceA->cancel($cancelHook['id'],$cancelRun['id']));
    $assert($callA(fn()=>$serviceA->history($cancelHook['id']))['webhookEffects'][0]['status']==='cancelled','Cancellation must stop unclaimed webhook delivery.');
    $transport=new \Espo\Custom\Tools\Automation\WorkflowWebhookTransport();
    foreach(['http://example.com','https://localhost','https://127.0.0.1','https://a.internal','https://user:pass@example.com','https://example.com:444','https://example.com/#fragment'] as $url)$blocked(fn()=>$transport->validateUrl($url),'Unsafe webhook destination must be rejected.');
    foreach(['127.0.0.1','10.0.0.1','100.64.0.1','169.254.169.254','224.0.0.1','198.18.0.1','::1','::ffff:8.8.8.8','2002:0808:0808::1','2001:db8::1'] as $ip)$assert(!$transport->publicAddress($ip),'Non-public or tunneled addresses must fail closed.');
    $assert($transport->publicAddress('8.8.8.8') && $transport->publicAddress('2606:4700:4700::1111'),'Public direct addresses must remain usable.');
    $retryContact=$callA(fn()=>$em->createEntity('Contact',['firstName'=>'Retry','lastName'=>'Probe','department'=>'Before retry']));
    $retryGraph=$nativeAction('contact.update',['field'=>'department','value'=>'Retry verified']);$retryGraph['nodes'][0]['maxAttempts']=3;
    $retryDraft=$create($retryGraph);$callA(fn()=>$serviceA->publish($retryDraft['id'],1));$retryRun=$callA(fn()=>$serviceA->enroll($retryDraft['id'],$retryContact->getId(),'native-rollback-retry'));
    $hookManager=$container->getByClass(\Espo\Core\HookManager::class);$hookState=[];
    foreach(['data','hooks','hookListHash'] as $name){$property=new ReflectionProperty($hookManager,$name);$hookState[$name]=[$property,$property->getValue($hookManager)];}
    $probe=new WorkflowRollbackProbe($retryContact->getId());
    $data=$hookState['data'][1];$data['Contact']['afterSave'][]=['className'=>WorkflowRollbackProbe::class,'order'=>100000];$hookState['data'][0]->setValue($hookManager,$data);
    $hooks=$hookState['hooks'][1];$hooks[WorkflowRollbackProbe::class]=$probe;$hookState['hooks'][0]->setValue($hookManager,$hooks);
    $hash=$hookState['hookListHash'][1];unset($hash['Contact_afterSave']);$hookState['hookListHash'][0]->setValue($hookManager,$hash);
    try {
        for($attempt=1;$attempt<=3;$attempt++){
            $tick();$retryState=$callA(fn()=>$history($serviceA,$retryDraft['id'],$retryRun['id']));
            $column=$pdo->prepare('SELECT department FROM contact WHERE id=?');$column->execute([$retryContact->getId()]);
            $assert($column->fetchColumn()===($attempt<3?'Before retry':'Retry verified'),'Failed native hooks must roll back their record update before retry.');
            $assert($retryState['status']===($attempt<3?'waiting':'ready'),'Transient failures must retain a durable retry until successful.');
            $pdo->prepare('UPDATE nexa_workflow_run SET due_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE id=?')->execute([$retryRun['id']]);
        }
        $tick();$assert($callA(fn()=>$history($serviceA,$retryDraft['id'],$retryRun['id']))['status']==='completed','Successful bounded retry must finish its enrollment.');
        $attemptCount=$pdo->prepare('SELECT attempt_count FROM nexa_workflow_step_attempt WHERE run_id=?');$attemptCount->execute([$retryRun['id']]);
        $assert((int)$attemptCount->fetchColumn()===3,'Native retry evidence must persist all three attempts.');
    } finally {foreach($hookState as [$property,$value])$property->setValue($hookManager,$value);}
    $quota=$factory->create(\Espo\Custom\Tools\Automation\WorkflowQuota::class);
    $limits=$callA(fn()=>$quota->limits(true));
    $blocked(fn()=>$callA(fn()=>$quota->requireCreation(array_replace($limits,['workflows'=>0]))),'Commercial workflow capacity must reject a new draft.');
    $blocked(fn()=>$callA(fn()=>$quota->requireEnrollment(array_replace($limits,['maxActiveRuns'=>0]))),'Active enrollment capacity must fail closed.');
    $period='workflow.steps:'.$pdo->query('SELECT UTC_DATE()')->fetchColumn();
    $pdo->prepare('DELETE FROM nexa_usage_counter WHERE tenant_id=? AND service_id=? AND period_key=?')->execute([$a->tenantId,$limits['serviceId'],$period]);
    $small=array_replace($limits,['maxStepsPerDay'=>1]);
    $assert($callA(fn()=>$quota->consumeStep($small)),'A tenant may consume its remaining daily step.');
    $assert(!$callA(fn()=>$quota->consumeStep($small)),'Daily step capacity must prevent another consumption.');
    $assert($callB(fn()=>$quota->consumeStep(array_replace($callB(fn()=>$quota->limits(true)),['maxStepsPerDay'=>100000]))),'One tenant exhausting capacity must not exhaust another tenant.');
    $pdo->prepare('DELETE FROM nexa_usage_counter WHERE tenant_id=? AND service_id=? AND period_key=?')->execute([$a->tenantId,$limits['serviceId'],$period]);
    // A large backlog must not displace another workflow's first due enrollment.
    $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=? AND status='published'")->execute([$a->tenantId,$a->serviceId]);
    $fair=$endOnly;
    $busy=$create($fair);$quiet=$create($fair);$callA(fn()=>$serviceA->publish($busy['id'],1));$callA(fn()=>$serviceA->publish($quiet['id'],1));
    for($i=0;$i<26;$i++){
        $fairContact=$callA(fn()=>$em->createEntity('Contact',['firstName'=>'Fair','lastName'=>'Backlog '.$i]));
        $callA(fn()=>$serviceA->enroll($busy['id'],$fairContact->getId(),'fair-busy-'.$i));
    }
    $quietRun=$callA(fn()=>$serviceA->enroll($quiet['id'],$nativeContact->getId(),'fair-quiet-one'));
    $tick();
    $assert($callA(fn()=>$history($serviceA,$quiet['id'],$quietRun['id']))['status']==='completed','A busy workflow must not starve a quieter workflow in the same batch.');
    $busyRuns=$callA(fn()=>$serviceA->history($busy['id']))['runs'];
    $assert(count(array_filter($busyRuns,fn($r)=>$r['status']==='completed'))===5,'Per-workflow batching must enforce its fairness bound.');
    // Catalogue-wide authorization checks reuse real drafts, not mocked services.
    $updatePermissionDraft = $create($nativeAction('opportunity.update', ['opportunityId' => $stageRecord->getId(), 'field' => 'description', 'value' => 'Permission matrix']));
    $callA(fn () => $serviceA->publish($updatePermissionDraft['id'], 1));
    $permissionCases = [];
    foreach ($matrixDrafts as $item) {
        $permissionCases['trigger:' . $item['definition']['trigger']['event']] ??= $item;
        foreach ($item['definition']['nodes'] as $node) {
            if ($node['type'] === 'action') $permissionCases['action:' . $node['action']] ??= $item;
        }
    }
    $catalogue = $callA(fn () => $serviceA->workspace());
    foreach (['supportedTriggers' => 'trigger:', 'supportedActions' => 'action:'] as $key => $prefix) {
        foreach ($catalogue[$key] as $name) $assert(isset($permissionCases[$prefix . $name]), 'Permission matrix must cover advertised ' . $prefix . $name);
    }
    $authorizeDenied = static function (callable $callback, string $message) use ($assert): void {
        $denied = false;
        try {$callback();} catch (Forbidden | NotFound) {$denied = true;}
        $assert($denied, $message);
    };
    foreach ($permissionCases as $label => $item) {
        $workflowId = $item['id'];
        $input = (object) ['name' => 'Permission matrix', 'definition' => $asObject($item['definition']), 'expectedRevision' => 1];
        foreach ([
            'staff' => [$regular, $a],
            'foreign tenant' => [$serviceB, $b],
            'foreign service' => [$serviceA, $other],
        ] as $identity => [$candidate, $context]) {
            $operations = [
                'read' => fn () => $candidate->detail($workflowId),
                'history' => fn () => $candidate->history($workflowId),
                'update' => fn () => $candidate->save($input, $workflowId),
                'publish' => fn () => $candidate->publish($workflowId, 1),
                'resume' => fn () => $candidate->control($workflowId, 'published'),
                'enroll' => fn () => $candidate->enroll($workflowId, $contactA->getId(), 'permission-matrix'),
                'event' => fn () => $candidate->intakeEvent($workflowId, $contactA->getId(), 'permission-matrix'),
                'cancel' => fn () => $candidate->cancel($workflowId, $run['id']),
                'review delivery' => fn () => $candidate->resolveWebhook($workflowId, $run['id'], 'success', 'Permission matrix'),
            ];
            foreach ($operations as $operation => $callback) {
                $authorizeDenied(fn () => $contexts->runWith($context, $callback), "$label: $identity must not $operation.");
            }
        }
        foreach (['inactive' => ['is_active', 0], 'downgraded' => ['type', 'regular']] as $identity => [$column, $value]) {
            $pdo->prepare("UPDATE user SET $column=? WHERE id=?")->execute([$value, $adminA->getId()]);
            try {
                $authorizeDenied(fn () => $callA(fn () => $serviceA->publish($workflowId, 1)), "$label: $identity administrator must not publish.");
                $authorizeDenied(fn () => $callA(fn () => $serviceA->enroll($workflowId, $contactA->getId(), 'revoked-matrix')), "$label: $identity administrator must not enroll.");
                $authorizeDenied(fn () => $callA(fn () => $serviceA->intakeEvent($workflowId, $contactA->getId(), 'revoked-matrix')), "$label: $identity administrator must not submit events.");
            } finally {
                $pdo->prepare("UPDATE user SET $column=? WHERE id=?")->execute([$column === 'type' ? 'admin' : 1, $adminA->getId()]);
            }
        }
    }
    // Revocation is rechecked by the real source polling worker before reading events.
    $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=?")->execute([$a->tenantId, $a->serviceId]);
    $automaticCases = [];
    foreach ($catalogue['supportedTriggers'] as $trigger) {
        if (in_array($trigger, ['manual', 'api.event'], true)) continue;
        $workflowId = $permissionCases['trigger:' . $trigger]['id'];
        $query = $pdo->prepare('SELECT published_version FROM nexa_workflow WHERE id=?');
        $query->execute([$workflowId]);
        $assert((bool) $query->fetchColumn(), "$trigger: source permission fixture must have a real published version.");
        $pdo->prepare("UPDATE nexa_workflow SET status='published' WHERE id=?")->execute([$workflowId]);
        $automaticCases[$trigger] = $workflowId;
    }
    foreach (['inactive' => ['is_active', 0], 'downgraded' => ['type', 'regular']] as $identity => [$column, $value]) {
        $pdo->prepare("UPDATE user SET $column=? WHERE id=?")->execute([$value, $adminA->getId()]);
        try {
            $receiptCount = $pdo->prepare('SELECT COUNT(*) FROM nexa_workflow_trigger_receipt WHERE tenant_id=? AND service_id=?');
            $receiptCount->execute([$a->tenantId, $a->serviceId]);
            $before = (int) $receiptCount->fetchColumn();
            $assert($callA(fn () => $worker->processAutomatic()) === 0, "$identity: no automatic trigger may enroll.");
            foreach ($automaticCases as $trigger => $workflowId) {
                $query = $pdo->prepare('SELECT blocked_reason FROM nexa_workflow_trigger_scan WHERE workflow_id=? AND tenant_id=? AND service_id=?');
                $query->execute([$workflowId, $a->tenantId, $a->serviceId]);
                $assert($query->fetchColumn() === 'access_denied', "$trigger: $identity execution administrator must block source polling.");
            }
            $receiptCount->execute([$a->tenantId, $a->serviceId]);
            $assert((int) $receiptCount->fetchColumn() === $before, "$identity: blocked triggers must not consume source receipts.");
        } finally {
            $pdo->prepare("UPDATE user SET $column=? WHERE id=?")->execute([$column === 'type' ? 'admin' : 1, $adminA->getId()]);
        }
    }
    // Seed due action boundaries from real immutable publications. These are
    // rollback-only worker probes, not claims of fresh authenticated enrollment.
    $versions = $pdo->prepare('SELECT workflow_id,version_number,definition_json FROM nexa_workflow_version WHERE tenant_id=? AND service_id=? AND execution_user_id=?');
    $versions->execute([$a->tenantId, $a->serviceId, $adminA->getId()]);
    $actionVersions = [];
    foreach ($versions->fetchAll(PDO::FETCH_ASSOC) as $version) {
        foreach (json_decode($version['definition_json'], true, 64, JSON_THROW_ON_ERROR)['nodes'] as $node) {
            if ($node['type'] === 'action') $actionVersions[$node['action']] ??= [$version, $node['id']];
        }
    }
    foreach ($catalogue['supportedActions'] as $action) {
        // The governed email fixture supplies the actual approved send source.
        if ($action !== 'email.send') $assert(isset($actionVersions[$action]), "$action: runtime permission probe requires a real publication.");
    }
    $snapshot = static function () use ($pdo, $a): string {
        $rows = [];
        foreach (['contact', 'account', 'opportunity', 'task', 'case', 'notification'] as $table) {
            $query = $pdo->prepare("SELECT * FROM `$table` WHERE tenant_id=? AND service_id=? ORDER BY id");
            $query->execute([$a->tenantId, $a->serviceId]);
            $rows[$table] = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    };
    foreach (['inactive' => ['is_active', 0], 'downgraded' => ['type', 'regular']] as $identity => [$column, $value]) {
        $pdo->prepare("UPDATE nexa_workflow SET status='paused' WHERE tenant_id=? AND service_id=?")->execute([$a->tenantId, $a->serviceId]);
        $pdo->prepare("UPDATE nexa_workflow_run SET status='cancelled' WHERE tenant_id=? AND service_id=? AND status IN ('ready','waiting')")->execute([$a->tenantId, $a->serviceId]);
        $probes = [];
        foreach ($actionVersions as $action => [$version, $nodeId]) {
            $probeId = $pdo->query('SELECT UUID()')->fetchColumn();
            $pdo->prepare("INSERT INTO nexa_workflow_run (id,tenant_id,service_id,workflow_id,version_number,contact_id,request_key_hash,status,node_id,node_entered_at,due_at,enrolled_by_id) VALUES (?,?,?,?,?,?,?,'ready',?,UTC_TIMESTAMP(6),DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND),?)")->execute([$probeId, $a->tenantId, $a->serviceId, $version['workflow_id'], $version['version_number'], $nativeContact->getId(), hash('sha256', $probeId), $nodeId, $adminA->getId()]);
            $pdo->prepare("UPDATE nexa_workflow SET status='published' WHERE id=?")->execute([$version['workflow_id']]);
            $probes[$action] = $probeId;
        }
        $before = $snapshot();
        $pdo->prepare("UPDATE user SET $column=? WHERE id=?")->execute([$value, $adminA->getId()]);
        try {
            $tick();
            foreach ($probes as $action => $probeId) {
                $query = $pdo->prepare('SELECT status FROM nexa_workflow_run WHERE id=?');
                $query->execute([$probeId]);
                $assert($query->fetchColumn() === 'failed', "$action: $identity actor must fail before execution.");
                $query = $pdo->prepare("SELECT COUNT(*) FROM nexa_workflow_event WHERE run_id=? AND event_type='failed' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.code'))='access_denied'");
                $query->execute([$probeId]);
                $assert((int) $query->fetchColumn() === 1, "$action: permission failure must have explicit audit evidence.");
                $query = $pdo->prepare('SELECT COUNT(*) FROM nexa_workflow_step_attempt WHERE run_id=?');
                $query->execute([$probeId]);
                $assert((int) $query->fetchColumn() === 0, "$action: denied actor must never attempt the adapter.");
                $query = $pdo->prepare('SELECT COUNT(*) FROM nexa_workflow_webhook_effect WHERE run_id=?');
                $query->execute([$probeId]);
                $assert((int) $query->fetchColumn() === 0, "$action: denied actor must create no external effect.");
            }
            $assert($snapshot() === $before, "$identity: rejected adapters must leave native records untouched.");
        } finally {
            $pdo->prepare("UPDATE user SET $column=? WHERE id=?")->execute([$column === 'type' ? 'admin' : 1, $adminA->getId()]);
        }
    }
    echo 'Catalogue permission matrix passed (' . count($permissionCases) . " trigger/action rows).\n";
    echo "Tenant workflow runtime passed ($checks checks).\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    throw $error;
} finally {$tx->rollback();}
