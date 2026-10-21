<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Record\CreateParams;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DecimalComparison;
use PDO;
use stdClass;

/** Scoped orchestration; native Contact reads retain the execution user's ACL. */
final class WorkflowService
{
    private const FIELDS = ['firstName', 'lastName', 'title', 'department', 'addressCountry', 'contactSource', 'lifecycleStage', 'leadStatus', 'marketingStatus', 'customerStage', 'leadScore'];
    private const ACTION_FIELDS = ['lifecycle.set' => 'lifecycleStage', 'owner.assign' => 'assignedUserId'];
    private const AUTOMATIC_TRIGGERS = ['form.submitted', 'page.viewed', 'asset.downloaded', 'email.replied', ...WorkflowTriggerSource::EVENTS];

    public function __construct(
        private EntityManager $em,
        private TenantContextStore $contexts,
        private User $user,
        private AclManager $aclManager,
        private ServiceFactory $records,
        private Metadata $metadata,
        private WorkflowDefinition $definitions,
        private WorkflowClock $clock,
        private \Espo\Custom\Tools\Customization\CustomizationService $customization,
        private InjectableFactory $factory,
        private \Espo\Core\Utils\Config $config,
    ) {}

    public function workspace(): array
    {
        $this->requireAdmin();
        return ['list' => $this->all('SELECT id,name,status,draft_revision AS draftRevision,published_version AS publishedVersion,created_at AS createdAt,modified_at AS modifiedAt FROM nexa_workflow WHERE tenant_id=? AND service_id=? ORDER BY modified_at DESC,id LIMIT 200', $this->scope()),
            'supportedTriggers' => ['manual','api.event', ...self::AUTOMATIC_TRIGGERS], 'supportedActions' => [...array_keys(self::ACTION_FIELDS), 'task.create', 'segment.add', 'segment.remove', 'case.create', 'notification.create', 'opportunity.create', 'email.send','webhook.send',...WorkflowRecordActions::ACTIONS], 'conditionFields' => $this->conditionFields($this->user),
            'campaigns'=>$this->campaigns($this->user),'updateProperties'=>$this->factory->create(WorkflowRecordActions::class)->properties($this->user),
            'opportunityOptions' => $this->opportunityOptions($this->user),
            'emailSources' => $this->emailSources($this->user),
            'caseOptions' => ['priority' => $this->metadata->get(['entityDefs', 'Case', 'fields', 'priority', 'options'], []), 'category' => $this->metadata->get(['entityDefs', 'Case', 'fields', 'category', 'options'], [])],
            'staticLists' => $this->staticLists($this->user),
            'enrollmentLists' => $this->enrollmentLists($this->user),
            'lifecycleOptions' => $this->metadata->get(['entityDefs', 'Contact', 'fields', 'lifecycleStage', 'options'], [])];
    }

    public function detail(string $id): array
    {
        $this->requireAdmin();
        $row = $this->workflow($id);
        return $this->detailRow($row);
    }

    public function save(stdClass $input, ?string $id = null): array
    {
        $this->requireAdmin();
        if (array_diff(array_keys((array) $input), ['name', 'definition', 'expectedRevision']) || !is_string($input->name ?? null) || !mb_check_encoding($input->name, 'UTF-8') || trim($input->name) === '' || mb_strlen($input->name) > 255 || preg_match('/[\x00-\x1f]/u', $input->name) || !(($input->definition ?? null) instanceof stdClass)) throw new BadRequest('Provide a name and workflow definition.');
        $definition = $this->definitions->validate($this->fromObject($input->definition))['definition'];
        return $this->atomic(function () use ($id, $input, $definition): array {
            $this->lockTenant();
            $pdo = $this->em->getPDO();
            if ($id === null) {
                if (property_exists($input, 'expectedRevision')) throw new BadRequest('A new workflow does not have a revision.');
                $quota=$this->factory->create(WorkflowQuota::class);$quota->requireCreation($quota->limits(true));
                $id = $this->uuid();
                $pdo->prepare('INSERT INTO nexa_workflow (id,tenant_id,service_id,name,draft_json,created_by_id,modified_by_id,created_at,modified_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute([$id, ...$this->scope(), trim($input->name), $this->json($definition), $this->user->getId(), $this->user->getId()]);
            } else {
                $row = $this->workflow($id, true);
                if ($row['status'] === 'archived') throw new BadRequest('Archived workflows cannot be edited.');
                if (!is_int($input->expectedRevision ?? null) || $input->expectedRevision !== (int) $row['draft_revision']) throw new BadRequest('This workflow changed. Reload it before saving.');
                $pdo->prepare('UPDATE nexa_workflow SET name=?,draft_json=?,draft_revision=draft_revision+1,modified_by_id=?,modified_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([trim($input->name), $this->json($definition), $this->user->getId(), $id, ...$this->scope()]);
            }
            $row = $this->workflow($id);
            $this->event($id, null, 'draft.saved', null, $this->user->getId(), ['revision' => (int) $row['draft_revision']]);
            return $this->detailRow($row);
        });
    }

    public function publish(string $id, int $expectedRevision): array
    {
        $this->requireAdmin();
        return $this->atomic(function () use ($id, $expectedRevision): array {
            $this->lockTenant();
            $row = $this->workflow($id, true);
            if ($row['status'] === 'archived' || $expectedRevision !== (int) $row['draft_revision']) throw new BadRequest('Reload the current draft before publishing.');
            $validated = $this->definitions->validate($this->decode($row['draft_json']));
            $this->requireSupported($validated['definition'], $this->user);
            $version = (int) $row['published_version'];
            if ($version) {
                $prior = $this->version($id, $version);
                if (hash_equals($prior['definition_hash'], $validated['fingerprint']) && $prior['execution_user_id'] === $this->user->getId() && $row['status'] === 'published') return $this->detailRow($row);
            }
            $version++;
            $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_version (tenant_id,service_id,workflow_id,version_number,definition_json,definition_hash,execution_user_id,published_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([...$this->scope(), $id, $version, $this->json($validated['definition']), $validated['fingerprint'], $this->user->getId()]);
            $this->em->getPDO()->prepare("UPDATE nexa_workflow SET status='published',published_version=?,modified_by_id=?,modified_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?")->execute([$version, $this->user->getId(), $id, ...$this->scope()]);
            $this->event($id, null, 'published', null, $this->user->getId(), ['version' => $version, 'definitionHash' => $validated['fingerprint']]);
            return $this->detailRow($this->workflow($id));
        });
    }

    public function control(string $id, string $status): array
    {
        $this->requireAdmin();
        if (!in_array($status, ['published', 'paused', 'archived'], true)) throw new BadRequest('Choose resume, pause or archive.');
        return $this->atomic(function () use ($id, $status): array {
            $this->lockTenant();
            $row = $this->workflow($id, true);
            if ($row['status'] === $status) return $this->detailRow($row);
            if ($row['status'] === 'archived' || (!$row['published_version'] && $status !== 'archived')) throw new BadRequest('This workflow cannot change its running state.');
            if ($status === 'published') {
                $version = $this->version($id, (int) $row['published_version']);
                $actor = $this->executionUser($version['execution_user_id']);
                $this->requireSupported($this->decode($version['definition_json']), $actor);
            }
            $this->em->getPDO()->prepare('UPDATE nexa_workflow SET status=?,modified_by_id=?,modified_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$status, $this->user->getId(), $id, ...$this->scope()]);
            if ($status === 'archived') {
                foreach ($this->all("SELECT * FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? AND status IN ('ready','waiting') FOR UPDATE", [$id, ...$this->scope()]) as $run) $this->finish($run, 'cancelled', 'workflow.archived', $this->user->getId());
            }
            $this->event($id, null, $status, null, $this->user->getId(), []);
            return $this->detailRow($this->workflow($id));
        });
    }

    public function enroll(string $id, string $contactId, string $requestKey): array
    {
        $this->requireAdmin();
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $requestKey) || strlen($contactId) > 24 || $contactId === '') throw new BadRequest('Provide a Contact and an 8 to 128 character request key.');
        $this->contact($contactId, $this->user);
        return $this->atomic(function () use ($id, $contactId, $requestKey): array {
            $this->factory->create(WorkflowQuota::class)->limits(true);
            $row = $this->workflow($id, true);
            return $this->enrollLocked($row, $contactId, $requestKey, $this->user->getId());
        });
    }

    public function intakeEvent(string $id,string $contactId,string $requestKey): array
    {
        $this->requireAdmin();
        if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$requestKey) || $contactId==='' || strlen($contactId)>24)throw new BadRequest('Provide a Contact and an 8 to 128 character event key.');
        $this->contact($contactId,$this->user);
        return $this->atomic(function () use($id,$contactId,$requestKey): array {
            $this->factory->create(WorkflowQuota::class)->limits(true);$row=$this->workflow($id,true);
            if(!$row['published_version'])throw new BadRequest('Publish an API-event workflow first.');
            $definition=$this->decode($this->version($id,(int)$row['published_version'])['definition_json']);
            if($definition['trigger']['event']!=='api.event')throw new BadRequest('This workflow does not accept API events.');
            $identity=substr(hash('sha256',implode(':',[...$this->scope(),$id,$requestKey])),0,36);
            $run=$this->enrollLocked($row,$contactId,$requestKey,$this->user->getId(),$identity);
            $this->em->getPDO()->prepare("INSERT IGNORE INTO nexa_workflow_trigger_receipt (tenant_id,service_id,workflow_id,event_id,version_number,outcome,run_id,processed_at) VALUES (?,?,?,?,?,'enrolled',?,UTC_TIMESTAMP(6))")->execute([...$this->scope(),$id,$identity,$run['version'],$run['id']]);
            return $run;
        });
    }

    private function eligible(string $workflowId, string $contactId, array $definition): bool
    {
        $previous = $this->one("SELECT COUNT(*) AS total,SUM(status<>'completed') AS blocked FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? AND contact_id=?", [$workflowId, ...$this->scope(), $contactId]);
        return (int) $previous['total'] === 0 || ($definition['trigger']['reenrollment'] === 'after_completion' && (int) $previous['blocked'] === 0);
    }

    /** The caller holds the workflow lock and transaction. */
    private function enrollLocked(array $row, string $contactId, string $requestKey, string $enrolledBy, ?string $eventId = null): array
    {
            $id = $row['id'];
            $prior = $this->one('SELECT * FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? AND request_key_hash=?', [$id, ...$this->scope(), hash('sha256', $requestKey)]);
            if ($prior) {
                if ($prior['contact_id'] !== $contactId) throw new BadRequest('This request key already identifies a different Contact.');
                return $this->runOutput($prior);
            }
            if ($row['status'] !== 'published') throw new BadRequest('Publish or resume the workflow before enrolling a Contact.');
            $version = $this->version($id, (int) $row['published_version']);
            $definition = $this->decode($version['definition_json']);
            if ($eventId === null && $definition['trigger']['event'] !== 'manual') throw new BadRequest('This workflow requires its configured event, not manual enrollment.');
            $actor = $this->executionUser($version['execution_user_id']);
            $this->requireSupported($definition, $actor);
            $this->contact($contactId, $actor);
            if (!$this->eligible($id, $contactId, $definition)) throw new BadRequest('This Contact is not eligible for reenrollment.');
            $quota=$this->factory->create(WorkflowQuota::class);$quota->requireEnrollment($quota->limits());
            $runId = $this->uuid();
            $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_run (id,tenant_id,service_id,workflow_id,version_number,contact_id,request_key_hash,node_id,node_entered_at,due_at,enrolled_by_id,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6))')->execute([$runId, ...$this->scope(), $id, $row['published_version'], $contactId, hash('sha256', $requestKey), $definition['entry'], $enrolledBy]);
            $this->event($id, $runId, 'enrolled', $definition['entry'], $enrolledBy, ['version' => (int) $row['published_version']] + ($eventId === null ? [] : ['triggerEventId' => $eventId, 'trigger' => $definition['trigger']['event']]));
            return $this->runOutput($this->run($runId));
    }

    public function cancel(string $id, string $runId): array
    {
        $this->requireAdmin();
        return $this->atomic(function () use ($id, $runId): array {
            $this->lockTenant();
            $this->workflow($id, true);
            $run = $this->run($runId, true);
            if ($run['workflow_id'] !== $id) throw new NotFound('Enrollment not found.');
            if (in_array($run['status'], ['ready', 'waiting'], true)) $this->finish($run, 'cancelled', 'cancelled', $this->user->getId());
            return $this->runOutput($this->run($runId));
        });
    }

    public function resolveWebhook(string $id,string $runId,string $outcome,string $evidence): array
    {
        $this->requireAdmin();
        return $this->atomic(function () use($id,$runId,$outcome,$evidence): array {
            $this->lockTenant();
            $this->workflow($id,true);$run=$this->run($runId,true);if($run['workflow_id']!==$id)throw new NotFound('Enrollment not found.');
            $this->factory->create(WorkflowWebhookService::class)->resolve($id,$runId,$outcome,$evidence,$this->user);
            return $this->runOutput($run);
        });
    }

    public function history(string $id): array
    {
        $this->requireAdmin();
        $this->workflow($id);
        return ['webhookEffects'=>$this->all('SELECT id,run_id AS runId,node_id AS nodeId,status,attempts,last_http_status AS httpStatus,last_error_code AS errorCode,updated_at AS updatedAt FROM nexa_workflow_webhook_effect WHERE workflow_id=? AND tenant_id=? AND service_id=? ORDER BY updated_at DESC,id LIMIT 100',[$id,...$this->scope()]),'runs' => array_map(fn ($row) => $this->runOutput($row), $this->all('SELECT * FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? ORDER BY created_at DESC,id LIMIT 100', [$id, ...$this->scope()])),
            'events' => array_map(function ($row) { $row['payload'] = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR); unset($row['payload_json']); return $row; }, $this->all('SELECT id,run_id AS runId,event_type AS eventType,node_id AS nodeId,actor_user_id AS actorUserId,payload_json,created_at AS createdAt FROM nexa_workflow_event WHERE workflow_id=? AND tenant_id=? AND service_id=? ORDER BY created_at DESC,id LIMIT 200', [$id, ...$this->scope()]))];
    }

    /** Bounded round-robin enrollment from committed canonical events. */
    public function processAutomatic(): int
    {
        if (!$this->user->isSystem()) throw new Forbidden('Event enrollment is a background-job operation.');
        if (!$this->scopeEnabled()) return 0;
        $marks = implode(',', array_fill(0, count(self::AUTOMATIC_TRIGGERS), '?'));
        $workflows = $this->all("SELECT w.id FROM nexa_workflow w INNER JOIN nexa_workflow_version v ON v.workflow_id=w.id AND v.tenant_id=w.tenant_id AND v.service_id=w.service_id AND v.version_number=w.published_version LEFT JOIN nexa_workflow_trigger_scan s ON s.workflow_id=w.id AND s.tenant_id=w.tenant_id AND s.service_id=w.service_id WHERE w.tenant_id=? AND w.service_id=? AND w.status='published' AND JSON_UNQUOTE(JSON_EXTRACT(v.definition_json,'$.trigger.event')) IN ($marks) ORDER BY COALESCE(s.last_scanned_at,'1000-01-01'),w.id LIMIT 25", [...$this->scope(), ...self::AUTOMATIC_TRIGGERS]);
        $processed = 0;
        foreach ($workflows as $candidate) {
            $processed += $this->atomic(function () use ($candidate): int {
                try {$this->factory->create(WorkflowQuota::class)->limits(true);} catch (Forbidden $error) {return 0;}
                $row = $this->workflow($candidate['id'], true);
                if ($row['status'] !== 'published' || !$this->scopeEnabled()) return 0;
                $id = $row['id'];
                $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_trigger_scan (tenant_id,service_id,workflow_id,last_scanned_at) VALUES (?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_scanned_at=VALUES(last_scanned_at)')->execute([...$this->scope(), $id]);
                $scan = $this->one('SELECT blocked_reason,blocked_version FROM nexa_workflow_trigger_scan WHERE tenant_id=? AND service_id=? AND workflow_id=?', [...$this->scope(), $id]);
                $version = null;
                try {
                    $version = $this->version($id, (int) $row['published_version']);
                    $definition = $this->decode($version['definition_json']);
                    $actor = $this->executionUser($version['execution_user_id']);
                    $this->requireSupported($definition, $actor);
                    if (!in_array($definition['trigger']['event'], self::AUTOMATIC_TRIGGERS, true)) return 0;
                } catch (Forbidden | NotFound | BadRequest $error) {
                    $reason = $error instanceof Forbidden ? 'access_denied' : 'configuration_invalid';
                    if ($scan['blocked_reason'] !== $reason || (int) $scan['blocked_version'] !== (int) $row['published_version']) {
                        $this->event($id, null, 'trigger.blocked', null, $version['execution_user_id'] ?? $row['modified_by_id'], ['reason' => $reason, 'version' => (int) $row['published_version']]);
                    }
                    $this->em->getPDO()->prepare('UPDATE nexa_workflow_trigger_scan SET blocked_reason=?,blocked_version=? WHERE tenant_id=? AND service_id=? AND workflow_id=?')->execute([$reason, $row['published_version'], ...$this->scope(), $id]);
                    return 0;
                }
                if ($scan['blocked_reason'] !== null) $this->event($id, null, 'trigger.resumed', null, $actor->getId(), ['version' => (int) $row['published_version']]);
                $this->em->getPDO()->prepare('UPDATE nexa_workflow_trigger_scan SET blocked_reason=NULL,blocked_version=NULL WHERE tenant_id=? AND service_id=? AND workflow_id=?')->execute([...$this->scope(), $id]);
                // No high-water cursor: late commits and subsequently verified identities must remain discoverable.
                $url = $definition['trigger']['pageUrl'] ?? null;
                $urlFilter = $url === null ? '' : ' AND BINARY b.page_url = BINARY ?';
                $params = [...$this->scope(), $definition['trigger']['event'], $version['published_at'], $version['published_at'], $id];
                if ($url !== null) $params[] = $url;
                $events = in_array($definition['trigger']['event'],WorkflowTriggerSource::EVENTS,true)
                    ? $this->factory->create(WorkflowTriggerSource::class)->events($definition['trigger'],$version['published_at'],$id)
                    : $this->all("SELECT b.id,b.contact_id FROM nexa_behavior_event b WHERE b.tenant_id=? AND b.service_id=? AND b.event_type=? AND b.event_version=1 AND b.received_at>=? AND b.occurred_at>=? AND b.occurred_at<=UTC_TIMESTAMP(6) AND b.contact_id IS NOT NULL AND EXISTS (SELECT 1 FROM nexa_timeline_event t WHERE t.tenant_id=b.tenant_id AND t.service_id=b.service_id AND t.source_entity_type='BehaviorEvent' AND t.source_entity_id=b.id AND t.contact_id=b.contact_id AND t.event_type=b.event_type AND t.visibility IN ('internal','team','customer')) AND NOT EXISTS (SELECT 1 FROM nexa_workflow_trigger_receipt r WHERE r.tenant_id=b.tenant_id AND r.service_id=b.service_id AND r.workflow_id=? AND r.event_id=b.id)$urlFilter ORDER BY b.received_at,b.id LIMIT 25", $params);
                $consumed=0;
                foreach ($events as $event) {
                    $outcome = 'skipped'; $reason = null; $runId = null;
                    try {
                        $this->contact($event['contact_id'], $actor);
                        if($definition['trigger']['event']==='conversation.received'){
                            $type=$event['source_type']??'';
                            if(!in_array($type,['NexaMessagingConversation','NexaChatConversation'],true))throw new Forbidden('Invalid conversation source.');
                            $acl=$this->aclManager->createUserAcl($actor);
                            if(!$acl->checkScope($type,'read')||!$acl->checkField($type,'messages','read')||($type==='NexaMessagingConversation'&&!$acl->checkField($type,'contactAssociation','read')))throw new Forbidden('Conversation enrollment permission is required.');
                            $source=$this->records->createForUser($type,$actor)->getEntity($event['source_id']);$context=$this->contexts->require();
                            if(!$source||$source->get('deleted')||$source->get('tenantId')!==$context->tenantId||$source->get('serviceId')!==$context->serviceId||!$acl->checkEntity($source,'read'))throw new Forbidden('Conversation enrollment source is unavailable.');
                        }
                        if(in_array($definition['trigger']['event'],['opportunity.stage_changed','activity.completed'],true)){
                            $sourceType=$event['source_type'] ?? '';
                            if(!in_array($sourceType,$definition['trigger']['event']==='opportunity.stage_changed'?['Opportunity']:['Task','Call','Meeting'],true))throw new Forbidden('Invalid enrollment source.');
                            $source=$this->records->createForUser($sourceType,$actor)->getEntity($event['source_id']);$context=$this->contexts->require();
                            if($source->get('deleted') || $source->get('tenantId')!==$context->tenantId || $source->get('serviceId')!==$context->serviceId)throw new Forbidden('The enrollment source is unavailable.');
                        }
                        if (!$this->eligible($id, $event['contact_id'], $definition)) $reason = 'reenrollment_not_eligible';
                        else {
                            $run = $this->enrollLocked($row, $event['contact_id'], 'event:' . $event['id'], $actor->getId(), $event['id']);
                            $outcome = 'enrolled'; $runId = $run['id'];
                        }
                    } catch (WorkflowCapacityException $error) {break;}
                    catch (Forbidden | NotFound $error) {$reason = $error instanceof Forbidden ? 'access_denied' : 'record_unavailable';}
                    $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_trigger_receipt (tenant_id,service_id,workflow_id,event_id,version_number,outcome,reason,run_id,processed_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([...$this->scope(), $id, $event['id'], $row['published_version'], $outcome, $reason, $runId]);
                    if ($outcome === 'skipped') $this->event($id, null, 'trigger.skipped', null, $actor->getId(), ['triggerEventId' => $event['id'], 'reason' => $reason, 'version' => (int) $row['published_version']]);
                    $consumed++;
                }
                return $consumed;
            });
        }
        return $processed;
    }

    /** One locked step per run per batch; concurrent workers recheck due times under lock. */
    public function processDue(): int
    {
        if (!$this->user->isSystem()) throw new Forbidden('Workflow processing is a background-job operation.');
        if (!$this->scopeEnabled()) return 0;
        $workflows=$this->all("SELECT w.id FROM nexa_workflow w LEFT JOIN nexa_workflow_execution_scan s ON s.workflow_id=w.id AND s.tenant_id=w.tenant_id AND s.service_id=w.service_id WHERE w.tenant_id=? AND w.service_id=? AND w.status='published' AND EXISTS (SELECT 1 FROM nexa_workflow_run r WHERE r.workflow_id=w.id AND r.tenant_id=w.tenant_id AND r.service_id=w.service_id AND r.status IN ('ready','waiting') AND r.due_at<=UTC_TIMESTAMP(6)) ORDER BY COALESCE(s.last_processed_at,'1000-01-01'),w.id LIMIT 25",$this->scope());
        $buckets=[];$rows=[];
        foreach ($workflows as $workflow) $buckets[]=$this->all("SELECT id,workflow_id FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? AND status IN ('ready','waiting') AND due_at<=UTC_TIMESTAMP(6) ORDER BY due_at,id LIMIT 5",[$workflow['id'],...$this->scope()]);
        for ($slot=0;$slot<5 && count($rows)<25;$slot++) foreach ($buckets as $bucket) if (isset($bucket[$slot]) && count($rows)<25) $rows[]=$bucket[$slot];
        $processed = 0;
        foreach ($rows as $candidate) {
            $processed += $this->atomic(function () use ($candidate): int {
                $quota=$this->factory->create(WorkflowQuota::class);
                try {$limits=$quota->limits(true);} catch (Forbidden $error) {return 0;}
                // Match publication/cancellation lock order: workflow, then enrollment.
                $workflow = $this->workflow($candidate['workflow_id'], true);
                $run = $this->run($candidate['id'], true);
                $now = new DateTimeImmutable($this->one('SELECT UTC_TIMESTAMP(6) AS now', [])['now'], new DateTimeZone('UTC'));
                if ($workflow['status'] !== 'published' || !in_array($run['status'], ['ready', 'waiting'], true) || $this->time($run['due_at']) > $now || !$this->scopeEnabled()) return 0;
                if (!$quota->consumeStep($limits)) return 0;
                $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_execution_scan (tenant_id,service_id,workflow_id,last_processed_at) VALUES (?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_processed_at=VALUES(last_processed_at)')->execute([...$this->scope(),$workflow['id']]);
                $node=null;$attempt=0;
                try {
                    $version = $this->version($run['workflow_id'], (int) $run['version_number']);
                    $definition = $this->decode($version['definition_json']);
                    $actor = $this->executionUser($version['execution_user_id']);
                    $this->requireSupported($definition, $actor);
                    $contact = $this->contact($run['contact_id'], $actor);
                    $nodes = array_column($definition['nodes'], null, 'id');
                    if (!isset($nodes[$run['node_id']])) throw new BadRequest('The current workflow step is missing.');
                    $node=$nodes[$run['node_id']];
                    if($node['type']==='action' && $node['action']!=='webhook.send'){
                        $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_step_attempt (tenant_id,service_id,run_id,node_id,attempt_count,updated_at) VALUES (?,?,?,?,1,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE attempt_count=attempt_count+1,last_error_code=NULL,updated_at=VALUES(updated_at)')->execute([...$this->scope(),$run['id'],$node['id']]);
                        $attempt=(int)$this->one('SELECT attempt_count FROM nexa_workflow_step_attempt WHERE tenant_id=? AND service_id=? AND run_id=? AND node_id=?',[...$this->scope(),$run['id'],$node['id']])['attempt_count'];
                        $this->event($run['workflow_id'],$run['id'],'step.attempted',$node['id'],$actor->getId(),['attempt'=>$attempt]);
                    }
                    // Roll back native writes as well as advancement if a hook rejects the step.
                    $this->atomic(fn () => $this->step($run, $nodes[$run['node_id']], $contact, $now, $actor));
                } catch (Forbidden | NotFound | BadRequest $error) {
                    $this->finish($run, 'failed', 'failed', $version['execution_user_id'] ?? $run['enrolled_by_id'], ['code' => $error instanceof Forbidden ? 'access_denied' : ($error instanceof NotFound ? 'record_unavailable' : 'configuration_invalid')]);
                } catch (\RuntimeException $error) {
                    // A deadlock may have rolled back the entire transaction, not only the savepoint.
                    if(!$this->em->getPDO()->inTransaction())throw $error;
                    $retry=$node && $node['type']==='action' && $node['action']!=='webhook.send' && $attempt<$node['maxAttempts'];
                    if($node && $node['type']==='action')$this->em->getPDO()->prepare("UPDATE nexa_workflow_step_attempt SET last_error_code='execution_failed' WHERE tenant_id=? AND service_id=? AND run_id=? AND node_id=?")->execute([...$this->scope(),$run['id'],$node['id']]);
                    if($retry){
                        $due=$this->clock->delay($now,min(86400,$node['retrySeconds']*(2**max(0,$attempt-1))))->format('Y-m-d H:i:s.u');
                        $this->em->getPDO()->prepare("UPDATE nexa_workflow_run SET status='waiting',due_at=? WHERE id=? AND tenant_id=? AND service_id=?")->execute([$due,$run['id'],...$this->scope()]);
                        $this->event($run['workflow_id'],$run['id'],'step.retry_scheduled',$node['id'],$actor->getId(),['attempt'=>$attempt,'dueAt'=>$due,'code'=>'execution_failed']);
                    } else $this->finish($run,'failed','failed',$version['execution_user_id'] ?? $run['enrolled_by_id'],['code'=>'execution_failed','attempt'=>$attempt]);
                }
                return 1;
            });
        }
        return $processed;
    }

    private function step(array $run, array $node, \Espo\ORM\Entity $contact, DateTimeImmutable $now, User $actor): void
    {
        $actorId = $actor->getId();
        if ($node['type'] === 'end') {$this->finish($run, 'completed', 'completed', $actorId); return;}
        $due = $now;
        $next = $node['next'] ?? null;
        $same = false;
        $payload = [];
        if($node['type']==='action' && $node['action']==='webhook.send') {
            $effect=$this->factory->create(WorkflowWebhookService::class)->stage($run,$node,$contact,$actor);
            if(in_array($effect['status'],['failed','cancelled'],true))throw new BadRequest('Webhook delivery failed or was cancelled.');
            if($effect['status']!=='success') {
                $this->em->getPDO()->prepare("UPDATE nexa_workflow_run SET status='waiting',due_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND) WHERE id=? AND tenant_id=? AND service_id=?")->execute([$run['id'],...$this->scope()]);
                return;
            }
            $payload=['action'=>'webhook.send','effectId'=>$effect['id'],'deliveryState'=>'success'];
        } elseif($node['type']==='action' && in_array($node['action'],WorkflowRecordActions::ACTIONS,true)) {
            $payload=$this->factory->create(WorkflowRecordActions::class)->execute($node,$contact,$actor);
            $payload['actionKey']=hash('sha256',implode(':',[...$this->scope(),$run['id'],$run['version_number'],$node['id']]));
        } elseif ($node['type'] === 'action' && $node['action'] === 'email.send') {
            $this->requireAction($node, $actor);
            $key = 'workflow:' . hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']]));
            // Queue creation and node advancement share the transaction; no provider call occurs here.
            $parameters = (new WorkflowEmailBindings())->resolve($node['settings'], $this->conditionFields($actor), fn (array $field) => $this->conditionValue($contact, $field));
            $result = $this->emailDispatch($actor)->reusableSend($node['settings']['sourceId'], $contact->getId(), $key, $node['settings']['version'], $parameters);
            $payload = ['action' => 'email.send', 'actionKey' => $key, 'queueItemId' => $result['queueItemId'], 'deliveryState' => 'queued', 'sourceId' => $node['settings']['sourceId'], 'sourceVersion' => $node['settings']['version']];
        } elseif ($node['type'] === 'action' && in_array($node['action'], ['segment.add', 'segment.remove'], true)) {
            $this->requireAction($node, $actor);
            $result = $this->segments($actor)->changeMembership($node['settings']['segmentId'], $contact->getId(), $node['action'] === 'segment.add');
            $payload = $result + ['action' => $node['action'], 'actionKey' => hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']]))];
        } elseif ($node['type'] === 'action' && $node['action'] === 'opportunity.create') {
            $this->requireAction($node, $actor);
            $settings = $node['settings'];
            $opportunity = $this->records->createForUser('Opportunity', $actor)->create((object) [
                'name' => $settings['name'], 'assignedUserId' => $settings['ownerId'] ?? $actorId,
                'contactId' => $contact->getId(), 'contactsIds' => [$contact->getId()],
                'pipelineId' => $settings['pipelineId'], 'stage' => $settings['stage'],
                'amount' => (float) $settings['amount'], 'amountCurrency' => $settings['currency'], 'closeDate' => $settings['closeDate'],
            ], CreateParams::create());
            if (!$this->one('SELECT id FROM opportunity WHERE id=? AND tenant_id=? AND service_id=? AND contact_id=? AND deleted=0', [$opportunity->getId(), ...$this->scope(), $contact->getId()])) throw new BadRequest('The native Opportunity was not created in this workspace.');
            $payload = ['action' => 'opportunity.create', 'actionKey' => hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']])), 'opportunityId' => $opportunity->getId()];
        } elseif ($node['type'] === 'action' && $node['action'] === 'notification.create') {
            $this->requireAction($node, $actor);
            $recipient = $this->records->createForUser('User', $actor)->getEntity($node['settings']['ownerId']);
            if (!$this->aclManager->createUserAcl($recipient)->check($contact, 'read')) throw new Forbidden('The notification recipient cannot read this Contact.');
            $notification = $this->em->createEntity(\Espo\Entities\Notification::ENTITY_TYPE, [
                'type' => \Espo\Entities\Notification::TYPE_SYSTEM, 'userId' => $recipient->getId(),
                'message' => $node['settings']['message'], 'relatedType' => 'Contact', 'relatedId' => $contact->getId(),
                'createdById' => $actorId,
            ], [\Espo\Core\ORM\Repository\Option\SaveOption::CREATED_BY_ID => $actorId]);
            if (!$this->one('SELECT id FROM notification WHERE id=? AND tenant_id=? AND service_id=? AND user_id=? AND related_id=?', [$notification->getId(), ...$this->scope(), $recipient->getId(), $contact->getId()])) throw new BadRequest('The notification was not created in this workspace.');
            $payload = ['action' => 'notification.create', 'actionKey' => hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']])), 'notificationId' => $notification->getId(), 'recipientId' => $recipient->getId()];
        } elseif ($node['type'] === 'action' && $node['action'] === 'case.create') {
            $this->requireAction($node, $actor);
            $data = ['name' => $node['settings']['name'], 'contactId' => $contact->getId(), 'contactsIds' => [$contact->getId()], 'assignedUserId' => $node['settings']['ownerId'] ?? $actorId];
            foreach (['priority', 'category'] as $field) if (array_key_exists($field, $node['settings'])) $data[$field] = $node['settings'][$field];
            $case = $this->records->createForUser('Case', $actor)->create((object) $data, CreateParams::create());
            if (!$this->one('SELECT id FROM `case` WHERE id=? AND tenant_id=? AND service_id=? AND contact_id=? AND deleted=0', [$case->getId(), ...$this->scope(), $contact->getId()])) throw new BadRequest('The native Case was not created in this workspace.');
            $payload = ['action' => 'case.create', 'actionKey' => hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']])), 'caseId' => $case->getId()];
        } elseif ($node['type'] === 'action' && $node['action'] === 'task.create') {
            $this->requireAction($node, $actor);
            $task = $this->records->createForUser('Task', $actor)->create((object) [
                'name' => $node['settings']['name'], 'parentType' => 'Contact', 'parentId' => $contact->getId(),
                'assignedUserId' => $node['settings']['ownerId'] ?? $actorId,
            ], CreateParams::create());
            // Scope injection may be SQL-side; verify the persisted record, not the unsaved attributes.
            if (!$this->one('SELECT id FROM task WHERE id=? AND tenant_id=? AND service_id=? AND parent_type=? AND parent_id=? AND deleted=0', [$task->getId(), ...$this->scope(), 'Contact', $contact->getId()])) throw new BadRequest('The native task was not created in this workspace.');
            $payload = ['action' => 'task.create', 'actionKey' => hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']])), 'taskId' => $task->getId()];
        } elseif ($node['type'] === 'action') {
            $this->requireAction($node, $actor);
            // Different workflows may update the same Contact: serialize the read/modify/write.
            if (!$this->one('SELECT id FROM contact WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 FOR UPDATE', [$contact->getId(), ...$this->scope()])) throw new NotFound('Contact not found.');
            $contact = $this->contact($contact->getId(), $actor);
            $acl = $this->aclManager->createUserAcl($actor);
            if (!$acl->check($contact, 'edit')) throw new Forbidden('Contact edit access is required.');
            $field = self::ACTION_FIELDS[$node['action']];
            $before = $contact->get($field);
            $value = match ($node['action']) {
                'lifecycle.set' => $node['settings']['stage'],
                'owner.assign' => $node['settings']['ownerId'],
            };
            if ($before !== $value) {
                $updated = $this->records->createForUser('Contact', $actor)->update($contact->getId(), (object) [$field => $value], UpdateParams::create());
                if ($updated->get($field) !== $value) throw new BadRequest('The native Contact update was not applied.');
            }
            $payload = ['action' => $node['action'], 'actionKey' => hash('sha256', implode(':', [...$this->scope(), $run['id'], $run['version_number'], $node['id']])), 'changed' => $before !== $value, 'field' => $field, 'previousValue' => $before, 'value' => $value];
        } elseif ($node['type'] === 'delay') {
            $deadline = $this->clock->delay($this->time($run['node_entered_at']), $node['seconds']);
            $due = $deadline > $now ? $deadline : $now;
        }
        elseif ($node['type']==='wait_until') {
            $deadline=new DateTimeImmutable(\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison::canonical($node['at']),new DateTimeZone('UTC'));
            $due=$deadline>$now?$deadline:$now;
        }
        elseif ($node['type'] === 'business_hours') {
            $settings = array_intersect_key($node, array_flip(['timezone', 'weekdays', 'start', 'end']));
            $due = $this->clock->businessHours($now, $settings);
            // Reevaluate at wake-up, including a delayed worker that missed the interval.
            if ($due > $now) {$next = $node['id']; $same = true;}
        } elseif ($node['type'] === 'ab_split') {
            $publication=$this->version($run['workflow_id'],(int)$run['version_number']);
            $identity=[...$this->scope(),$run['workflow_id'],(int)$run['version_number'],$node['id'],$contact->getId()];
            $selected=WorkflowAbRouting::assignment($identity,$publication['definition_hash'],(int)$node['weightA']);
            $this->em->getPDO()->prepare('INSERT IGNORE INTO nexa_workflow_ab_assignment (tenant_id,service_id,workflow_id,version_number,run_id,node_id,contact_id,branch_key,assignment_sha256,weight_a) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([...$this->scope(),$run['workflow_id'],$run['version_number'],$run['id'],$node['id'],$contact->getId(),$selected['branch'],$selected['hash'],$node['weightA']]);
            $saved=$this->one('SELECT * FROM nexa_workflow_ab_assignment WHERE tenant_id=? AND service_id=? AND run_id=? AND node_id=? FOR UPDATE',[...$this->scope(),$run['id'],$node['id']]);
            if(!$saved || $saved['workflow_id']!==$run['workflow_id'] || (int)$saved['version_number']!==(int)$run['version_number'] || $saved['contact_id']!==$contact->getId() || (int)$saved['weight_a']!==(int)$node['weightA'] || !hash_equals($saved['assignment_sha256'],$selected['hash']) || $saved['branch_key']!==$selected['branch']) throw new BadRequest('Persisted A/B routing no longer matches its published workflow.');
            $next=$saved['branch_key']==='A'?$node['yes']:$node['no'];
            $payload=['abVariant'=>$saved['branch_key'],'weightA'=>(int)$saved['weight_a'],'assignmentHash'=>$saved['assignment_sha256']];
        } elseif (in_array($node['type'], ['branch', 'wait_condition'], true)) {
            $condition = $node['condition'];
            $fields = array_column($this->conditionFields($actor), null, 'name');
            $matches = [];
            foreach ($condition['conditions'] ?? [$condition] as $rule) {
                $field = $fields[$rule['field']];
                $matches[] = $field['type'] === 'behavior' ? $this->behaviorMatches($contact->getId(), $rule) : $this->associatedMatches($contact,$field,$rule,$actor);
            }
            $match = ($condition['matchMode'] ?? 'all') === 'all' ? !in_array(false, $matches, true) : in_array(true, $matches, true);
            if(($condition['matchMode'] ?? '')==='sequence')$match=$this->sequenceMatches($contact->getId(),$condition['conditions']);
            $deadline = $node['type'] === 'wait_condition' ? $this->clock->delay($this->time($run['node_entered_at']), $node['timeoutSeconds']) : null;
            if ($deadline !== null && $now >= $deadline) {$match = false; $payload['timedOut'] = true;}
            $payload['matched'] = $match;
            $next = $match ? $node['yes'] : $node['no'];
            if ($node['type'] === 'wait_condition' && !$match) {
                if ($now < $deadline) {
                    $poll = $this->clock->delay($now, $node['pollSeconds']);
                    $due = $poll < $deadline ? $poll : $deadline;
                    $next = $node['id']; $same = true;
                }
            }
        } else throw new BadRequest('This step is not executable.');
        $stamp = $due->format('Y-m-d H:i:s.u');
        $this->em->getPDO()->prepare('UPDATE nexa_workflow_run SET node_id=?,status=?,due_at=?,node_entered_at=? WHERE id=? AND tenant_id=? AND service_id=?')->execute([$next, $due > $now ? 'waiting' : 'ready', $stamp, $same ? $run['node_entered_at'] : $stamp, $run['id'], ...$this->scope()]);
        $this->event($run['workflow_id'], $run['id'], $same ? 'step.waiting' : 'step.completed', $node['id'], $actorId, $payload + ['next' => $next, 'dueAt' => $stamp]);
    }

    private function associatedMatches(\Espo\ORM\Entity $contact,array $field,array $rule,User $actor): bool
    {
        if(isset($field['segmentId'])) {
            $list=$this->records->createForUser('TargetList',$actor)->getEntity($field['segmentId']);
            $context=$this->contexts->require();
            if($list->get('deleted') || $list->get('tenantId')!==$context->tenantId || $list->get('serviceId')!==$context->serviceId)throw new Forbidden('The list is unavailable.');
            $member=(bool)$this->one('SELECT 1 FROM contact_target_list WHERE tenant_id=? AND service_id=? AND contact_id=? AND target_list_id=? AND deleted=0',[...$this->scope(),$contact->getId(),$list->getId()]);
            return $this->matches($member?'true':'false',$rule,'boolean');
        }
        if(!isset($field['relatedType']))return $this->matches($this->conditionValue($contact,$field),$rule,$field['type']);
        $ids=$field['relatedType']==='Account'?array_filter([(string)$contact->get('accountId')]):array_column($this->all('SELECT o.id FROM opportunity o WHERE o.tenant_id=? AND o.service_id=? AND o.deleted=0 AND (o.contact_id=? OR EXISTS (SELECT 1 FROM contact_opportunity r WHERE r.tenant_id=o.tenant_id AND r.service_id=o.service_id AND r.opportunity_id=o.id AND r.contact_id=? AND r.deleted=0)) ORDER BY o.id LIMIT 101',[...$this->scope(),$contact->getId(),$contact->getId()]),'id');
        if(count($ids)>100)throw new BadRequest('Associated Opportunity conditions support at most 100 records.');
        $values=[];
        foreach($ids as $id) {
            $record=$this->records->createForUser($field['relatedType'],$actor)->getEntity($id);$context=$this->contexts->require();
            if($record->get('deleted') || $record->get('tenantId')!==$context->tenantId || $record->get('serviceId')!==$context->serviceId)throw new Forbidden('An associated record is unavailable.');
            $values[]=$record->get($field['relatedField']);
        }
        if($rule['operator']==='is_empty')return !array_filter($values,fn($value)=>$value!==null && $value!=='');
        if($rule['operator']==='not_equals')return $values!==[] && !in_array(true,array_map(fn($value)=>$this->matches($value,array_replace($rule,['operator'=>'equals']),$field['type']),$values),true);
        return in_array(true,array_map(fn($value)=>$this->matches($value,$rule,$field['type']),$values),true);
    }

    private function conditionValue(\Espo\ORM\Entity $contact, array $field): mixed
    {
        if (!isset($field['customId'])) return $contact->get($field['name']);
        // Read DECIMAL as text: the generic UI value adapter converts numbers to floats.
        $row = $this->one('SELECT value_text,value_number,value_boolean,value_date,value_datetime FROM nexa_custom_field_value WHERE tenant_id=? AND service_id=? AND entity_type=? AND entity_id=? AND field_definition_id=?', [...$this->scope(), 'Contact', $contact->getId(), $field['customId']]);
        if (!$row) return $field['defaultValue'];
        return match ($field['type']) {
            'decimal' => $row['value_number'],
            'date' => $row['value_date'],
            'datetime' => $row['value_datetime'],
            'boolean' => $row['value_boolean'] === null ? null : ((bool) $row['value_boolean'] ? 'true' : 'false'),
            default => $row['value_text'],
        };
    }

    private function sequenceMatches(string $contactId,array $rules): bool
    {
        $after=null;
        foreach($rules as $rule) {
            $event=substr($rule['field'],9);$source=\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::EVENTS[$event];
            $params=[...$this->scope(),$contactId,$event,$source,(int)$rule['value']];
            $filter='';
            if($after!==null){$filter.=' AND t.source_occurred_at>?';$params[]=$after;}
            if(isset($rule['pageUrl'])){$filter.=' AND EXISTS (SELECT 1 FROM nexa_behavior_event b WHERE b.id=t.source_entity_id AND b.tenant_id=t.tenant_id AND b.service_id=t.service_id AND b.contact_id=t.contact_id AND BINARY b.page_url=BINARY ?)';$params[]=$rule['pageUrl'];}
            if(isset($rule['campaignId'])){$filter.=$this->campaignFilter();$params[]=$rule['campaignId'];}
            $proof=$source==='BehaviorEvent'?'EXISTS (SELECT 1 FROM nexa_behavior_event b WHERE b.id=t.source_entity_id AND b.tenant_id=t.tenant_id AND b.service_id=t.service_id AND b.contact_id=t.contact_id AND b.event_type=t.event_type)':'EXISTS (SELECT 1 FROM nexa_marketing_delivery_event e WHERE e.id=t.source_entity_id AND e.tenant_id=t.tenant_id AND e.service_id=t.service_id AND CONCAT(\'email.\',e.event_type)=t.event_type)';
            $row=$this->one("SELECT t.source_occurred_at FROM nexa_timeline_event t WHERE t.tenant_id=? AND t.service_id=? AND t.contact_id=? AND t.event_type=? AND t.source_entity_type=? AND t.visibility IN ('internal','team','customer') AND t.source_occurred_at BETWEEN DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? DAY) AND UTC_TIMESTAMP(6) AND {$proof}{$filter} ORDER BY t.source_occurred_at,t.id LIMIT 1",$params);
            if(!$row)return false;
            $after=$row['source_occurred_at'];
        }
        return true;
    }

    private function behaviorMatches(string $contactId, array $condition): bool
    {
        $event = substr($condition['field'], 9);
        $source = \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::EVENTS[$event];
        $pageUrl=$condition['pageUrl'] ?? null;
        $filter=$pageUrl===null ? '' : ' AND EXISTS (SELECT 1 FROM nexa_behavior_event b WHERE b.id=t.source_entity_id AND b.tenant_id=t.tenant_id AND b.service_id=t.service_id AND b.contact_id=t.contact_id AND b.event_type=t.event_type AND BINARY b.page_url=BINARY ?)';
        $where="t.tenant_id=? AND t.service_id=? AND t.contact_id=? AND t.event_type=? AND t.source_entity_type=? AND t.visibility IN ('internal','team','customer') AND t.source_occurred_at BETWEEN DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? DAY) AND UTC_TIMESTAMP(6)" . $filter;
        $params=[...$this->scope(),$contactId,$event,$source,(int)$condition['value'],...($pageUrl===null ? [] : [$pageUrl])];
        if(isset($condition['campaignId'])){$where.=$this->campaignFilter();$params[]=$condition['campaignId'];}
        if (str_starts_with($condition['operator'], 'count_')) {
            // Count canonical source identities, not duplicate timeline projections.
            $row = $this->one("SELECT COUNT(*) AS total FROM (SELECT DISTINCT t.source_entity_id FROM nexa_timeline_event t WHERE {$where} AND t.source_entity_id IS NOT NULL LIMIT 10001) AS counted_events", $params);
            $count = (int) $row['total'];
            return match ($condition['operator']) {
                'count_at_least' => $count >= $condition['count'],
                'count_at_most' => $count <= $condition['count'],
                'count_equals' => $count === $condition['count'],
            };
        }
        $found = (bool) $this->one("SELECT 1 FROM nexa_timeline_event t WHERE {$where} LIMIT 1", $params);
        return $condition['operator'] === 'within_days' ? $found : !$found;
    }

    private function matches(mixed $value, array $condition, string $type): bool
    {
        if ($type === 'date') return \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::matches($value, $condition['operator'] === 'is_empty' ? 'no_value' : $condition['operator'], $condition['value'] ?? '');
        if ($type === 'datetime') return \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison::matches($value, $condition['operator'] === 'is_empty' ? 'no_value' : $condition['operator'], $condition['value'] ?? '');
        $present = $value !== null && $value !== '';
        if ($condition['operator'] === 'has_value') return $present;
        if ($condition['operator'] === 'is_empty') return !$present;
        if (!$present) return false;
        if (in_array($type, ['int', 'decimal'], true)) {
            $actual = DecimalComparison::canonical((string) $value);
            $expected = DecimalComparison::canonical((string) $condition['value']);
            $comparison = $actual[0] !== $expected[0] ? ($actual[0] === '-' ? -1 : 1) : strcmp(substr($actual, 1), substr($expected, 1)) * ($actual[0] === '-' ? -1 : 1);
            return match ($condition['operator']) {'equals' => $comparison === 0, 'not_equals' => $comparison !== 0, 'greater_than' => $comparison > 0, 'less_than' => $comparison < 0};
        }
        $equal = (string) $value === (string) $condition['value'];
        return $condition['operator'] === 'equals' ? $equal : !$equal;
    }

    private function requireSupported(array $definition, User $actor): void
    {
        if (!in_array($definition['trigger']['event'], ['manual','api.event', ...self::AUTOMATIC_TRIGGERS], true)) throw new BadRequest('This enrollment trigger is not available yet.');
        $fields = array_column($this->conditionFields($actor), null, 'name');
        if($definition['trigger']['event']==='date.reached' && (!isset($fields[$definition['trigger']['field']]) || !in_array($fields[$definition['trigger']['field']]['type'],['date','datetime'],true)))throw new Forbidden('Choose an enabled Contact date or date-time property.');
        if(in_array($definition['trigger']['event'],['opportunity.stage_changed','activity.completed'],true)){
            $acl=$this->aclManager->createUserAcl($actor);
            foreach($definition['trigger']['event']==='opportunity.stage_changed'?['Opportunity'=>['stage']]:['Task'=>['status'],'Call'=>['status'],'Meeting'=>['status']] as $type=>$names) {
                if(!$acl->checkScope($type,'read'))throw new Forbidden('Enrollment source read access is required.');
                foreach($names as $field)if(!$acl->checkField($type,$field,'read'))throw new Forbidden('Enrollment source property access is required.');
            }
        }
        if ($definition['trigger']['event']==='contact.field_changed' && !isset($fields[$definition['trigger']['field']])) throw new Forbidden('The enrollment property is unavailable to the execution user.');
        if ($definition['trigger']['event']==='score.reached' && !isset($fields['leadScore'])) throw new Forbidden('Score read access is required.');
        if (in_array($definition['trigger']['event'],['segment.entered','segment.exited'],true)) {
            $list=$this->records->createForUser('TargetList',$actor)->getEntity($definition['trigger']['segmentId']);
            $context=$this->contexts->require();
            if ($list->get('deleted') || $list->get('tenantId')!==$context->tenantId || $list->get('serviceId')!==$context->serviceId || !$this->one("SELECT 1 FROM nexa_segment_definition WHERE target_list_id=? AND tenant_id=? AND service_id=? AND status='active'",[$list->getId(),...$this->scope()])) throw new Forbidden('Choose an active readable list in this workspace.');
        }
        foreach ($definition['nodes'] as $node) {
            if ($node['type'] === 'action') $this->requireAction($node, $actor);
            foreach (isset($node['condition']) ? ($node['condition']['conditions'] ?? [$node['condition']]) : [] as $condition) {
                $field = $condition['field'];
                if (!isset($fields[$field])) throw new Forbidden('A condition property is unavailable to the execution user.');
                $type = $fields[$field]['type'];
                if ($type === 'behavior') {
                    if(isset($condition['campaignId'])){
                        $campaign=$this->records->createForUser('Campaign',$actor)->getEntity($condition['campaignId']);$context=$this->contexts->require();
                        if($campaign->get('deleted') || $campaign->get('tenantId')!==$context->tenantId || $campaign->get('serviceId')!==$context->serviceId)throw new Forbidden('The campaign condition is unavailable.');
                    }
                    continue;
                }
                $relative = in_array($condition['operator'], \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::RELATIVE_OPERATORS, true);
                if ($relative && !in_array($type, ['date','datetime'], true)) throw new BadRequest('Relative dates require a date or date-time property.');
                if (in_array($condition['operator'], ['before', 'after', 'on_or_before', 'on_or_after'], true) && !in_array($type, ['date','datetime'], true)) throw new BadRequest('Date comparisons require a date or date-time property.');
                if (in_array($condition['operator'], ['greater_than', 'less_than'], true) && !in_array($type, ['int', 'decimal'], true)) throw new BadRequest('Numeric conditions need a supported numeric property.');
                if (array_key_exists('value', $condition)) {
                    $value = $condition['value'];
                    if ($relative) \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::windowDays($value);
                    elseif ($type === 'date') \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::canonical($value);
                    elseif ($type === 'datetime') \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison::canonical($value);
                    if (in_array($type, ['int', 'decimal'], true)) {
                        if (!is_string($value) && !is_int($value)) throw new BadRequest('Numeric properties need a numeric comparison value.');
                        DecimalComparison::canonical((string) $value);
                    } elseif (!is_string($value)) throw new BadRequest('Text properties need a text comparison value.');
                    if (in_array($type, ['enum', 'boolean'], true) && !in_array($value, $fields[$field]['options'], true)) throw new BadRequest('Choose a supported property option.');
                }
            }
        }
    }

    private function requireAction(array $node, User $actor): void
    {
        if($node['action']==='webhook.send') {$this->factory->create(WorkflowWebhookService::class)->validate($node['settings']['webhookId'],$actor);return;}
        if(in_array($node['action'],WorkflowRecordActions::ACTIONS,true)) {$this->factory->create(WorkflowRecordActions::class)->validate($node,$actor);return;}
        if ($node['action'] === 'email.send') {
            if ($node['maxAttempts'] !== 1) throw new BadRequest('Email queue creation uses one atomic attempt; delivery retries belong to the dispatcher.');
            $parameters = (new WorkflowEmailBindings())->resolve($node['settings'], $this->conditionFields($actor));
            $this->emailDispatch($actor)->validateWorkflowSource($node['settings']['sourceId'], $node['settings']['version'], $parameters);
            return;
        }
        if ($node['action'] === 'notification.create') {
            $recipient = $this->records->createForUser('User', $actor)->getEntity($node['settings']['ownerId']);
            $context = $this->contexts->require();
            $acl = $this->aclManager->createUserAcl($actor);
            if (!$recipient instanceof User || $recipient->get('deleted') || !$recipient->isActive() || !in_array($recipient->getType(), [User::TYPE_ADMIN, User::TYPE_REGULAR], true) || $recipient->get('tenantId') !== $context->tenantId || $recipient->get('serviceId') !== $context->serviceId || !$acl->checkAssignmentPermission($recipient)) throw new Forbidden('Choose an active assignable staff member in this workspace.');
            if (!$this->aclManager->createUserAcl($recipient)->checkScope('Contact', 'read')) throw new Forbidden('The notification recipient requires Contact read access.');
            return;
        }
        if (in_array($node['action'], ['segment.add', 'segment.remove'], true)) {
            $this->segments($actor)->validateMembershipTarget($node['settings']['segmentId']);
            return;
        }
        if (in_array($node['action'], ['task.create', 'case.create', 'opportunity.create'], true)) {
            $entityType = match ($node['action']) {'case.create' => 'Case', 'opportunity.create' => 'Opportunity', default => 'Task'};
            $acl = $this->aclManager->createUserAcl($actor);
            if (!$acl->checkScope($entityType, 'create')) throw new Forbidden('Record creation access is required.');
            $fields = match ($entityType) {'Case' => ['name', 'contact', 'contacts', 'assignedUser'], 'Opportunity' => ['name', 'contact', 'contacts', 'assignedUser', 'pipelineId', 'stage', 'amount', 'closeDate'], default => ['name', 'parent', 'assignedUser']};
            foreach ($fields as $name) if (!$acl->checkField($entityType, $name, 'edit')) throw new Forbidden('Record property edit access is required.');
            if (trim($node['settings']['name']) === '') throw new BadRequest('Enter a record name.');
            if ($entityType === 'Opportunity') {
                $settings = $node['settings'];
                $stage = $this->one('SELECT s.id,s.required_fields_json FROM nexa_sales_stage s INNER JOIN nexa_sales_pipeline p ON p.id=s.pipeline_id AND p.tenant_id=s.tenant_id AND p.service_id=s.service_id WHERE s.tenant_id=? AND s.service_id=? AND p.id=? AND s.name=? AND p.is_active=1 AND s.is_active=1', [...$this->scope(), $settings['pipelineId'], $settings['stage']]);
                if (!$stage) throw new BadRequest('Choose an active stage in this workspace pipeline.');
                $provided = ['name', 'assignedUserId', 'contactId', 'contactsIds', 'pipelineId', 'stage', 'amount', 'amountCurrency', 'closeDate'];
                try {$requiredFields = json_decode($stage['required_fields_json'] ?: '[]', true, 32, JSON_THROW_ON_ERROR);} catch (\JsonException $error) {throw new BadRequest('The sales stage requirements are invalid.');}
                if (!is_array($requiredFields) || !array_is_list($requiredFields)) throw new BadRequest('The sales stage requirements are invalid.');
                foreach ($requiredFields as $required) if (!in_array($required, $provided, true)) throw new BadRequest('This stage requires additional properties that this workflow action cannot supply.');
                if (!in_array($settings['currency'], (array) $this->config->get('currencyList', []), true)) throw new BadRequest('Choose an enabled workspace currency.');
            }
            if ($entityType === 'Case') foreach (['priority', 'category'] as $field) {
                if (!array_key_exists($field, $node['settings'])) continue;
                if (!$acl->checkField('Case', $field, 'edit')) throw new Forbidden('Case property edit access is required.');
                if (!in_array($node['settings'][$field], $this->metadata->get(['entityDefs', 'Case', 'fields', $field, 'options'], []), true)) throw new BadRequest('Choose a configured Case ' . $field . '.');
            }
            $owner = $this->records->createForUser('User', $actor)->getEntity($node['settings']['ownerId'] ?? $actor->getId());
            $context = $this->contexts->require();
            if (!$owner instanceof User || $owner->get('deleted') || !$owner->isActive() || !in_array($owner->getType(), [User::TYPE_ADMIN, User::TYPE_REGULAR], true) || $owner->get('tenantId') !== $context->tenantId || $owner->get('serviceId') !== $context->serviceId || !$acl->checkAssignmentPermission($owner)) throw new Forbidden('Choose an active assignable staff member in this workspace.');
            return;
        }
        $field = self::ACTION_FIELDS[$node['action']] ?? null;
        if ($field === null) throw new BadRequest('This action is not executable yet.');
        // Only transactional native effects reach this branch; provider delivery has its own protocol.
        $acl = $this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('Contact', 'edit') || !$acl->checkField('Contact', $field, 'edit')) throw new Forbidden('Contact property edit access is required.');
        if ($node['action'] === 'lifecycle.set') {
            $options = $this->metadata->get(['entityDefs', 'Contact', 'fields', $field, 'options'], []);
            if ($node['settings']['stage'] === '' || !in_array($node['settings']['stage'], $options, true)) throw new BadRequest('Choose a configured lifecycle stage.');
        }
        if ($node['action'] === 'owner.assign') {
            $owner = $this->records->createForUser('User', $actor)->getEntity($node['settings']['ownerId']);
            $context = $this->contexts->require();
            if (!$owner instanceof User || $owner->get('deleted') || !$owner->isActive() || !in_array($owner->getType(), [User::TYPE_ADMIN, User::TYPE_REGULAR], true) || $owner->get('tenantId') !== $context->tenantId || $owner->get('serviceId') !== $context->serviceId || !$acl->checkAssignmentPermission($owner)) throw new Forbidden('Choose an active assignable staff member in this workspace.');
        }
    }

    private function campaignFilter(): string
    {
        return ' AND EXISTS (SELECT 1 FROM nexa_marketing_delivery_event e INNER JOIN nexa_marketing_email_profile p ON p.id=e.profile_id AND p.tenant_id=e.tenant_id AND p.service_id=e.service_id INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id AND m.deleted=0 INNER JOIN campaign c ON c.id=m.campaign_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id AND c.deleted=0 WHERE e.id=t.source_entity_id AND e.tenant_id=t.tenant_id AND e.service_id=t.service_id AND c.id=?)';
    }

    private function campaigns(User $actor): array
    {
        $acl=$this->aclManager->createUserAcl($actor);if(!$acl->checkScope('Campaign','read') || !$acl->checkField('Campaign','name','read'))return [];
        return array_values(array_filter($this->all('SELECT id,name FROM campaign WHERE tenant_id=? AND service_id=? AND deleted=0 ORDER BY name,id LIMIT 200',$this->scope()),function($row)use($actor):bool{try{$this->records->createForUser('Campaign',$actor)->getEntity($row['id']);return true;}catch(Forbidden|NotFound $error){return false;}}));
    }

    private function emailSources(User $actor): array
    {
        $acl = $this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('MassEmail', 'edit') || !$acl->checkScope('EmailTemplate', 'read') || !$acl->checkField('EmailTemplate', 'subject') || !$acl->checkField('EmailTemplate', 'body')) return [];
        $rows = $this->all("SELECT p.id,p.version_number AS version,p.mass_email_id,m.email_template_id,m.name,v.subject,v.body_html,v.preheader FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id AND m.deleted=0 INNER JOIN nexa_marketing_email_version v ON v.profile_id=p.id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.version_number=p.version_number WHERE p.tenant_id=? AND p.service_id=? AND p.lifecycle_status='approved' ORDER BY m.name,p.id LIMIT 200", $this->scope());
        $result = [];
        $parameters = $this->factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingParameterTemplate::class);
        foreach ($rows as $row) {
            $mass = $this->em->getEntityById('MassEmail', $row['mass_email_id']);
            $template = $this->em->getEntityById('EmailTemplate', $row['email_template_id']);
            if (!$mass || !$template || !$acl->check($mass, 'edit') || !$acl->check($template, 'read')) continue;
            try {$schema = $parameters->schema($row['subject'], $row['body_html'], (string) ($row['preheader'] ?? ''));} catch (BadRequest) {continue;}
            $result[] = ['id' => $row['id'], 'name' => $row['name'], 'version' => (int) $row['version'], 'parameters' => $schema];
        }
        return $result;
    }

    private function emailDispatch(User $actor): \Espo\Custom\Tools\MarketingEmail\MarketingDispatchService
    {
        $acl = $this->aclManager->createUserAcl($actor);
        $recordFactory = $this->factory->createWith(ServiceFactory::class, ['user' => $actor, 'acl' => $acl]);
        $recordServices = new \Espo\Core\Record\ServiceContainer($recordFactory);
        $workspace = $this->factory->createWith(\Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService::class, ['user' => $actor, 'acl' => $acl, 'recordServices' => $recordServices]);
        return $this->factory->createWith(\Espo\Custom\Tools\MarketingEmail\MarketingDispatchService::class, ['user' => $actor, 'acl' => $acl, 'workspace' => $workspace]);
    }

    private function segments(User $actor): \Espo\Custom\Tools\Segment\SegmentWorkspaceService
    {
        return $this->factory->createWith(\Espo\Custom\Tools\Segment\SegmentWorkspaceService::class, ['user' => $actor, 'acl' => $this->aclManager->createUserAcl($actor)]);
    }

    private function staticLists(User $actor): array
    {
        $acl = $this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('TargetList', 'read') || !$acl->checkScope('TargetList', 'edit')) return [];
        $rows = $this->all("SELECT t.id,t.name FROM target_list t INNER JOIN nexa_segment_definition d ON d.target_list_id=t.id AND d.tenant_id=t.tenant_id AND d.service_id=t.service_id WHERE t.tenant_id=? AND t.service_id=? AND t.deleted=0 AND d.status='active' AND d.segment_type='static' ORDER BY t.name,t.id LIMIT 200", $this->scope());
        return array_values(array_filter($rows, function ($row) use ($actor): bool {
            try {$this->segments($actor)->validateMembershipTarget($row['id']); return true;} catch (Forbidden | NotFound | BadRequest $error) {return false;}
        }));
    }

    private function opportunityOptions(User $actor): array
    {
        $acl = $this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('Opportunity', 'create')) return ['stages' => [], 'currencies' => []];
        return ['stages' => $this->all('SELECT p.id AS pipelineId,p.name AS pipelineName,s.name AS stage FROM nexa_sales_pipeline p INNER JOIN nexa_sales_stage s ON s.pipeline_id=p.id AND s.tenant_id=p.tenant_id AND s.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND p.is_active=1 AND s.is_active=1 ORDER BY p.position,p.id,s.position,s.id', $this->scope()), 'currencies' => (array) $this->config->get('currencyList', []), 'defaultCurrency' => $this->config->get('defaultCurrency')];
    }

    private function enrollmentLists(User $actor): array
    {
        $acl=$this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('TargetList','read')) return [];
        $rows=$this->all("SELECT t.id,t.name FROM target_list t INNER JOIN nexa_segment_definition d ON d.target_list_id=t.id AND d.tenant_id=t.tenant_id AND d.service_id=t.service_id WHERE t.tenant_id=? AND t.service_id=? AND t.deleted=0 AND d.status='active' ORDER BY t.name,t.id LIMIT 200",$this->scope());
        return array_values(array_filter($rows,function ($row) use ($actor): bool {
            try {$this->records->createForUser('TargetList',$actor)->getEntity($row['id']);return true;} catch (Forbidden | NotFound $error) {return false;}
        }));
    }

    private function conditionFields(User $actor): array
    {
        $acl = $this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('Contact', 'read')) throw new Forbidden('Contact read access is required.');
        $fields = [];
        foreach (self::FIELDS as $name) {
            $type = $this->metadata->get(['entityDefs', 'Contact', 'fields', $name, 'type']);
            if (in_array($type, ['varchar', 'enum', 'int'], true) && $acl->checkField('Contact', $name)) $fields[] = ['name' => $name, 'type' => $type, 'options' => $type === 'enum' ? $this->metadata->get(['entityDefs', 'Contact', 'fields', $name, 'options'], []) : []];
        }
        foreach ($this->customization->definitions('Contact')['fields'] as $field) {
            if (!$field['is_enabled'] || !in_array($field['data_type'], ['text', 'single_select', 'boolean', 'number', 'currency', 'date', 'datetime'], true)) continue;
            $type = match ($field['data_type']) {'number', 'currency' => 'decimal', 'single_select' => 'enum', 'boolean' => 'boolean', 'date' => 'date', 'datetime' => 'datetime', default => 'varchar'};
            $default = $field['defaultValue'];
            if ($type === 'boolean' && $default !== null) $default = $default ? 'true' : 'false';
            $fields[] = ['name' => 'custom:' . $field['field_key'], 'label' => $field['label'] . ' (custom)', 'type' => $type, 'options' => $type === 'boolean' ? ['true', 'false'] : $field['options'], 'customId' => $field['id'], 'defaultValue' => $default];
        }
        foreach (\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::EVENTS as $event => $source) {
            $fields[] = ['name' => 'behavior:' . $event, 'label' => 'Recorded ' . str_replace('.', ' ', $event), 'type' => 'behavior', 'options' => []];
        }
        foreach(['Account'=>['industry','type','lifecycleStage','leadStatus','leadScore'],'Opportunity'=>['stage','closeDate']] as $entityType=>$names) {
            if(!$acl->checkScope($entityType,'read'))continue;
            foreach($names as $name) {
                $type=$this->metadata->get(['entityDefs',$entityType,'fields',$name,'type']);
                if(!in_array($type,['varchar','enum','int','date'],true) || !$acl->checkField($entityType,$name,'read'))continue;
                $fields[]=['name'=>strtolower($entityType).':'.$name,'label'=>($entityType==='Account'?'Primary Account ':'Associated Opportunity ').$name,'type'=>$type,'options'=>$type==='enum'?$this->metadata->get(['entityDefs',$entityType,'fields',$name,'options'],[]):[],'relatedType'=>$entityType,'relatedField'=>$name];
            }
        }
        foreach($this->enrollmentLists($actor) as $list)$fields[]=['name'=>'segment:'.$list['id'],'label'=>'Member of '.$list['name'],'type'=>'boolean','options'=>['true','false'],'segmentId'=>$list['id']];
        return $fields;
    }

    private function executionUser(string $id): User
    {
        $actor = $this->em->getEntityById('User', $id);
        $context = $this->contexts->require();
        if (!$actor instanceof User || $actor->get('deleted') || !$actor->isActive() || $actor->getType() !== User::TYPE_ADMIN || $actor->get('tenantId') !== $context->tenantId || $actor->get('serviceId') !== $context->serviceId) throw new Forbidden('The workflow execution administrator is no longer available.');
        return $actor;
    }

    private function contact(string $id, User $actor): \Espo\ORM\Entity
    {
        $contact = $this->records->createForUser('Contact', $actor)->getEntity($id);
        $context = $this->contexts->require();
        if (!$contact || $contact->get('deleted') || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId) throw new NotFound('Contact not found.');
        return $contact;
    }

    private function requireAdmin(): void
    {
        if (!$this->scopeEnabled() || $this->user->getType() !== User::TYPE_ADMIN) throw new Forbidden('An active tenant administrator is required.');
        $this->executionUser($this->user->getId());
    }

    private function scopeEnabled(): bool
    {
        return (bool) $this->one("SELECT 1 FROM nexa_tenant_service ts INNER JOIN nexa_tenant t ON t.id=ts.tenant_id INNER JOIN nexa_service_definition sd ON sd.id=ts.service_id AND sd.status='active' WHERE ts.tenant_id=? AND ts.service_id=? AND ts.status='active' AND t.status='active' AND (ts.starts_at IS NULL OR ts.starts_at<=UTC_TIMESTAMP()) AND (ts.ends_at IS NULL OR ts.ends_at>UTC_TIMESTAMP())", $this->scope());
    }

    private function workflow(string $id, bool $lock = false): array
    {
        return $this->one('SELECT * FROM nexa_workflow WHERE id=? AND tenant_id=? AND service_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id, ...$this->scope()]) ?? throw new NotFound('Workflow not found.');
    }

    private function version(string $id, int $version): array
    {
        $row = $this->one('SELECT * FROM nexa_workflow_version WHERE workflow_id=? AND version_number=? AND tenant_id=? AND service_id=?', [$id, $version, ...$this->scope()]) ?? throw new NotFound('Workflow version not found.');
        $validated = $this->definitions->validate($this->decode($row['definition_json']));
        if (!hash_equals($row['definition_hash'], $validated['fingerprint'])) throw new BadRequest('Workflow version integrity failed.');
        return $row;
    }

    private function run(string $id, bool $lock = false): array
    {
        return $this->one('SELECT * FROM nexa_workflow_run WHERE id=? AND tenant_id=? AND service_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id, ...$this->scope()]) ?? throw new NotFound('Enrollment not found.');
    }

    private function detailRow(array $row): array
    {
        $trigger = $row['published_version'] === null ? null : $this->one("SELECT JSON_UNQUOTE(JSON_EXTRACT(definition_json,'$.trigger.event')) AS trigger_event FROM nexa_workflow_version WHERE workflow_id=? AND version_number=? AND tenant_id=? AND service_id=?", [$row['id'], $row['published_version'], ...$this->scope()])['trigger_event'];
        return ['id' => $row['id'], 'name' => $row['name'], 'status' => $row['status'], 'draftRevision' => (int) $row['draft_revision'], 'publishedVersion' => $row['published_version'] === null ? null : (int) $row['published_version'], 'publishedTrigger' => $trigger, 'definition' => $this->decode($row['draft_json'])];
    }

    private function runOutput(array $row): array
    {
        return ['id' => $row['id'], 'workflowId' => $row['workflow_id'], 'version' => (int) $row['version_number'], 'contactId' => $row['contact_id'], 'status' => $row['status'], 'nodeId' => $row['node_id'], 'dueAt' => $row['due_at'], 'createdAt' => $row['created_at'], 'finishedAt' => $row['finished_at']];
    }

    private function finish(array $run, string $status, string $event, string $actorId, array $payload = []): void
    {
        $this->factory->create(WorkflowWebhookService::class)->cancel($run['workflow_id'],$run['id']);
        $this->em->getPDO()->prepare('UPDATE nexa_workflow_run SET status=?,finished_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$status, $run['id'], ...$this->scope()]);
        $this->event($run['workflow_id'], $run['id'], $event, $run['node_id'], $actorId, $payload);
    }

    private function event(string $workflowId, ?string $runId, string $type, ?string $nodeId, string $actorId, array $payload): void
    {
        $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_event (id,tenant_id,service_id,workflow_id,run_id,event_type,node_id,actor_user_id,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$this->uuid(), ...$this->scope(), $workflowId, $runId, $type, $nodeId, $actorId, $this->json((object) $payload)]);
    }

    private function lockTenant(): void
    {
        // Audit/version foreign keys acquire tenant locks too; never take them after workflow locks.
        $row = $this->one('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE', [$this->contexts->require()->tenantId]);
        if (!$row) throw new Forbidden('Tenant unavailable.');
    }

    private function atomic(callable $callback): mixed
    {
        $pdo = $this->em->getPDO();
        $transactions = $this->em->getTransactionManager();
        $own = !$pdo->inTransaction();
        $savepoint = 'nexa_workflow_' . bin2hex(random_bytes(6));
        // Native Record services nest through this manager, not directly through PDO.
        if ($own) $transactions->start(); else $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $callback();
            if ($own) $transactions->commit(); else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $error) {
            if ($own) {
                if ($transactions->isStarted()) {
                    try {$transactions->rollback();}
                    catch (\Throwable $rollbackError) {if ($pdo->inTransaction()) throw $rollbackError;}
                } elseif ($pdo->inTransaction()) $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $error;
        }
    }

    private function all(string $sql, array $params): array {$query = $this->em->getPDO()->prepare($sql); $query->execute($params); return $query->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql, array $params): ?array {$query = $this->em->getPDO()->prepare($sql); $query->execute($params); return $query->fetch(PDO::FETCH_ASSOC) ?: null;}
    private function scope(): array {$context = $this->contexts->require(); return [$context->tenantId, $context->serviceId];}
    private function time(string $value): DateTimeImmutable {return new DateTimeImmutable($value, new DateTimeZone('UTC'));}
    private function json(mixed $value): string {return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);}
    private function decode(string $json): array
    {
        try {$parsed = json_decode($json, false, 64, JSON_THROW_ON_ERROR);}
        catch (\JsonException $error) {throw new BadRequest('The saved workflow definition is invalid.', 0, $error);}
        if (!$parsed instanceof stdClass) throw new BadRequest('The saved workflow definition must be an object.');
        return $this->fromObject($parsed);
    }
    private function fromObject(mixed $value, string $key = ''): mixed
    {
        if (in_array($key, ['parameters', 'parameterBindings'], true) && $value instanceof stdClass) return $value;
        if ($value instanceof stdClass) $value = (array) $value;
        if (!is_array($value)) return $value;
        foreach ($value as $name => $item) $value[$name] = $this->fromObject($item, (string) $name);
        return $value;
    }
    private function uuid(): string {$bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));}
}
