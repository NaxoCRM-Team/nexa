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
    $create = static fn (array $definition, string $name = 'Workflow runtime acceptance') => $callA(fn () => $serviceA->save((object) ['name' => $name, 'definition' => $asObject($definition)]));
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

    $unsupported = $base; $unsupported['trigger']['event'] = 'form.submitted';
    $unsupportedDraft = $create($unsupported);
    $blocked(fn () => $callA(fn () => $serviceA->publish($unsupportedDraft['id'], 1)), 'Unwired trigger subscriptions must not publish silently.');
    $unsupported = $base; $unsupported['nodes'][1] = ['id' => 'wait', 'type' => 'action', 'action' => 'email.send', 'settings' => ['sourceId' => 'example1', 'version' => 1], 'maxAttempts' => 1, 'retrySeconds' => 60, 'next' => 'end'];
    $unsupportedDraft = $create($unsupported);
    $blocked(fn () => $callA(fn () => $serviceA->publish($unsupportedDraft['id'], 1)), 'Unwired actions must not publish or send mail.');
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
    $assert($workspace['supportedActions'] === [] && $workspace['supportedTriggers'] === ['manual'], 'The API catalogue must not advertise unimplemented action or trigger execution.');
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
    echo "Tenant workflow runtime passed ($checks checks).\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    throw $error;
} finally {$tx->rollback();}
