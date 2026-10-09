<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
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

    public function __construct(
        private EntityManager $em,
        private TenantContextStore $contexts,
        private User $user,
        private AclManager $aclManager,
        private ServiceFactory $records,
        private Metadata $metadata,
        private WorkflowDefinition $definitions,
        private WorkflowClock $clock,
    ) {}

    public function workspace(): array
    {
        $this->requireAdmin();
        return ['list' => $this->all('SELECT id,name,status,draft_revision AS draftRevision,published_version AS publishedVersion,created_at AS createdAt,modified_at AS modifiedAt FROM nexa_workflow WHERE tenant_id=? AND service_id=? ORDER BY modified_at DESC,id LIMIT 200', $this->scope()),
            'supportedTriggers' => ['manual'], 'supportedActions' => [], 'conditionFields' => $this->conditionFields($this->user)];
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
            $pdo = $this->em->getPDO();
            if ($id === null) {
                if (property_exists($input, 'expectedRevision')) throw new BadRequest('A new workflow does not have a revision.');
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
            $row = $this->workflow($id, true);
            $prior = $this->one('SELECT * FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? AND request_key_hash=?', [$id, ...$this->scope(), hash('sha256', $requestKey)]);
            if ($prior) {
                if ($prior['contact_id'] !== $contactId) throw new BadRequest('This request key already identifies a different Contact.');
                return $this->runOutput($prior);
            }
            if ($row['status'] !== 'published') throw new BadRequest('Publish or resume the workflow before enrolling a Contact.');
            $version = $this->version($id, (int) $row['published_version']);
            $definition = $this->decode($version['definition_json']);
            $actor = $this->executionUser($version['execution_user_id']);
            $this->requireSupported($definition, $actor);
            $this->contact($contactId, $actor);
            $previous = $this->one("SELECT COUNT(*) AS total,SUM(status<>'completed') AS blocked FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? AND contact_id=?", [$id, ...$this->scope(), $contactId]);
            if ((int) $previous['total'] > 0 && ($definition['trigger']['reenrollment'] === 'never' || (int) $previous['blocked'] > 0)) throw new BadRequest('This Contact is not eligible for reenrollment.');
            $runId = $this->uuid();
            $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_run (id,tenant_id,service_id,workflow_id,version_number,contact_id,request_key_hash,node_id,node_entered_at,due_at,enrolled_by_id,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6))')->execute([$runId, ...$this->scope(), $id, $row['published_version'], $contactId, hash('sha256', $requestKey), $definition['entry'], $this->user->getId()]);
            $this->event($id, $runId, 'enrolled', $definition['entry'], $this->user->getId(), ['version' => (int) $row['published_version']]);
            return $this->runOutput($this->run($runId));
        });
    }

    public function cancel(string $id, string $runId): array
    {
        $this->requireAdmin();
        return $this->atomic(function () use ($id, $runId): array {
            $this->workflow($id, true);
            $run = $this->run($runId, true);
            if ($run['workflow_id'] !== $id) throw new NotFound('Enrollment not found.');
            if (in_array($run['status'], ['ready', 'waiting'], true)) $this->finish($run, 'cancelled', 'cancelled', $this->user->getId());
            return $this->runOutput($this->run($runId));
        });
    }

    public function history(string $id): array
    {
        $this->requireAdmin();
        $this->workflow($id);
        return ['runs' => array_map(fn ($row) => $this->runOutput($row), $this->all('SELECT * FROM nexa_workflow_run WHERE workflow_id=? AND tenant_id=? AND service_id=? ORDER BY created_at DESC,id LIMIT 100', [$id, ...$this->scope()])),
            'events' => array_map(function ($row) { $row['payload'] = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR); unset($row['payload_json']); return $row; }, $this->all('SELECT id,run_id AS runId,event_type AS eventType,node_id AS nodeId,actor_user_id AS actorUserId,payload_json,created_at AS createdAt FROM nexa_workflow_event WHERE workflow_id=? AND tenant_id=? AND service_id=? ORDER BY created_at DESC,id LIMIT 200', [$id, ...$this->scope()]))];
    }

    /** One locked step per run per batch; concurrent workers recheck due times under lock. */
    public function processDue(): int
    {
        if (!$this->user->isSystem()) throw new Forbidden('Workflow processing is a background-job operation.');
        if (!$this->scopeEnabled()) return 0;
        $rows = $this->all("SELECT r.id,r.workflow_id FROM nexa_workflow_run r INNER JOIN nexa_workflow w ON w.id=r.workflow_id AND w.tenant_id=r.tenant_id AND w.service_id=r.service_id WHERE r.tenant_id=? AND r.service_id=? AND w.status='published' AND r.status IN ('ready','waiting') AND r.due_at<=UTC_TIMESTAMP(6) ORDER BY r.due_at,r.id LIMIT 25", $this->scope());
        $processed = 0;
        foreach ($rows as $candidate) {
            $processed += $this->atomic(function () use ($candidate): int {
                // Match publication/cancellation lock order: workflow, then enrollment.
                $workflow = $this->workflow($candidate['workflow_id'], true);
                $run = $this->run($candidate['id'], true);
                $now = new DateTimeImmutable($this->one('SELECT UTC_TIMESTAMP(6) AS now', [])['now'], new DateTimeZone('UTC'));
                if ($workflow['status'] !== 'published' || !in_array($run['status'], ['ready', 'waiting'], true) || $this->time($run['due_at']) > $now || !$this->scopeEnabled()) return 0;
                try {
                    $version = $this->version($run['workflow_id'], (int) $run['version_number']);
                    $definition = $this->decode($version['definition_json']);
                    $actor = $this->executionUser($version['execution_user_id']);
                    $this->requireSupported($definition, $actor);
                    $contact = $this->contact($run['contact_id'], $actor);
                    $nodes = array_column($definition['nodes'], null, 'id');
                    if (!isset($nodes[$run['node_id']])) throw new BadRequest('The current workflow step is missing.');
                    $this->step($run, $nodes[$run['node_id']], $contact, $now, $actor->getId());
                } catch (Forbidden | NotFound | BadRequest $error) {
                    $this->finish($run, 'failed', 'failed', $version['execution_user_id'] ?? $run['enrolled_by_id'], ['code' => $error instanceof Forbidden ? 'access_denied' : ($error instanceof NotFound ? 'record_unavailable' : 'configuration_invalid')]);
                }
                return 1;
            });
        }
        return $processed;
    }

    private function step(array $run, array $node, \Espo\ORM\Entity $contact, DateTimeImmutable $now, string $actorId): void
    {
        if ($node['type'] === 'end') {$this->finish($run, 'completed', 'completed', $actorId); return;}
        $due = $now;
        $next = $node['next'] ?? null;
        $same = false;
        $payload = [];
        if ($node['type'] === 'delay') {
            $deadline = $this->clock->delay($this->time($run['node_entered_at']), $node['seconds']);
            $due = $deadline > $now ? $deadline : $now;
        }
        elseif ($node['type'] === 'business_hours') {
            $settings = array_intersect_key($node, array_flip(['timezone', 'weekdays', 'start', 'end']));
            $due = $this->clock->businessHours($now, $settings);
            // Reevaluate at wake-up, including a delayed worker that missed the interval.
            if ($due > $now) {$next = $node['id']; $same = true;}
        } elseif (in_array($node['type'], ['branch', 'wait_condition'], true)) {
            $match = $this->matches($contact->get($node['condition']['field']), $node['condition']);
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

    private function matches(mixed $value, array $condition): bool
    {
        $present = $value !== null && $value !== '';
        if ($condition['operator'] === 'has_value') return $present;
        if ($condition['operator'] === 'is_empty') return !$present;
        if (!$present) return false;
        if ($this->metadata->get(['entityDefs', 'Contact', 'fields', $condition['field'], 'type']) === 'int') {
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
        if ($definition['trigger']['event'] !== 'manual') throw new BadRequest('Automatic enrollment subscriptions are not available yet. Choose manual enrollment.');
        $fields = array_column($this->conditionFields($actor), 'type', 'name');
        foreach ($definition['nodes'] as $node) {
            if ($node['type'] === 'action') throw new BadRequest('Action execution is not available yet. This workflow cannot be published.');
            if (isset($node['condition'])) {
                $field = $node['condition']['field'];
                if (!isset($fields[$field])) throw new Forbidden('A condition property is unavailable to the execution user.');
                if (in_array($node['condition']['operator'], ['greater_than', 'less_than'], true) && $fields[$field] !== 'int') throw new BadRequest('Numeric conditions need a supported numeric property.');
                if (array_key_exists('value', $node['condition'])) {
                    $value = $node['condition']['value'];
                    if ($fields[$field] === 'int') {
                        if (!is_string($value) && !is_int($value)) throw new BadRequest('Numeric properties need a numeric comparison value.');
                        DecimalComparison::canonical((string) $value);
                    } elseif (!is_string($value)) throw new BadRequest('Text properties need a text comparison value.');
                    if ($fields[$field] === 'enum' && !in_array($value, $this->metadata->get(['entityDefs', 'Contact', 'fields', $field, 'options'], []), true)) throw new BadRequest('Choose a supported property option.');
                }
            }
        }
    }

    private function conditionFields(User $actor): array
    {
        $acl = $this->aclManager->createUserAcl($actor);
        if (!$acl->checkScope('Contact', 'read')) throw new Forbidden('Contact read access is required.');
        $fields = [];
        foreach (self::FIELDS as $name) {
            $type = $this->metadata->get(['entityDefs', 'Contact', 'fields', $name, 'type']);
            if (in_array($type, ['varchar', 'enum', 'int'], true) && $acl->checkField('Contact', $name)) $fields[] = ['name' => $name, 'type' => $type];
        }
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
        return ['id' => $row['id'], 'name' => $row['name'], 'status' => $row['status'], 'draftRevision' => (int) $row['draft_revision'], 'publishedVersion' => $row['published_version'] === null ? null : (int) $row['published_version'], 'definition' => $this->decode($row['draft_json'])];
    }

    private function runOutput(array $row): array
    {
        return ['id' => $row['id'], 'workflowId' => $row['workflow_id'], 'version' => (int) $row['version_number'], 'contactId' => $row['contact_id'], 'status' => $row['status'], 'nodeId' => $row['node_id'], 'dueAt' => $row['due_at'], 'createdAt' => $row['created_at'], 'finishedAt' => $row['finished_at']];
    }

    private function finish(array $run, string $status, string $event, string $actorId, array $payload = []): void
    {
        $this->em->getPDO()->prepare('UPDATE nexa_workflow_run SET status=?,finished_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$status, $run['id'], ...$this->scope()]);
        $this->event($run['workflow_id'], $run['id'], $event, $run['node_id'], $actorId, $payload);
    }

    private function event(string $workflowId, ?string $runId, string $type, ?string $nodeId, string $actorId, array $payload): void
    {
        $this->em->getPDO()->prepare('INSERT INTO nexa_workflow_event (id,tenant_id,service_id,workflow_id,run_id,event_type,node_id,actor_user_id,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$this->uuid(), ...$this->scope(), $workflowId, $runId, $type, $nodeId, $actorId, $this->json((object) $payload)]);
    }

    private function atomic(callable $callback): mixed
    {
        $pdo = $this->em->getPDO();
        $own = !$pdo->inTransaction();
        $savepoint = 'nexa_workflow_' . bin2hex(random_bytes(6));
        if ($own) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $callback();
            if ($own) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                if ($own) $pdo->rollBack();
                else {$pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);}
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
        if ($key === 'parameters' && $value instanceof stdClass) return $value;
        if ($value instanceof stdClass) $value = (array) $value;
        if (!is_array($value)) return $value;
        foreach ($value as $name => $item) $value[$name] = $this->fromObject($item, (string) $name);
        return $value;
    }
    private function uuid(): string {$bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));}
}
