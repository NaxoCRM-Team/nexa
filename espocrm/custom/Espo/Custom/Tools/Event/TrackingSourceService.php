<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Tenant-administrator configuration for public website event sources. */
final class TrackingSourceService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
        private Acl $acl,
        private Config $config,
        private BehaviorEventService $behaviorEventService,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT s.*,COUNT(e.id) AS event_count,MAX(e.received_at) AS last_event_at ' .
            'FROM nexa_tracking_source s LEFT JOIN nexa_behavior_event e ON e.tenant_id=s.tenant_id ' .
            'AND e.service_id=s.service_id AND e.source=CONCAT(\'web.\',REPLACE(LEFT(s.id,24),\'-\',\'\')) ' .
            'WHERE s.tenant_id=? AND s.service_id=? GROUP BY s.id ORDER BY s.created_at DESC'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);

        return [
            'sources' => array_map(fn (array $row): array => $this->payload($row), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'limits' => ['origins' => 20, 'eventsPerMinute' => 120, 'payloadBytes' => 98304],
        ];
    }

    /** @return array<string, mixed> */
    public function create(stdClass $data): array
    {
        return $this->save(null, $data);
    }

    /** @return array<string, mixed> */
    public function update(string $id, stdClass $data): array
    {
        return $this->save($id, $data);
    }

    /** @return array<string, mixed> */
    public function rotateKey(string $id): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_tracking_source SET public_key=?,modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?'
        );
        $statement->execute([bin2hex(random_bytes(24)), $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        if ($statement->rowCount() !== 1) {
            throw new NotFound('The tracking source is unavailable.');
        }

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function test(string $id): array
    {
        $this->requireAdmin();
        $source = $this->find($id);
        if ($source['status'] !== 'active') {
            throw new BadRequest('Activate this tracking source before sending a test event.');
        }
        $event = $this->behaviorEventService->ingest((object) [
            'eventType' => 'custom.tracking-source-test',
            'consentCategory' => 'necessary',
            'source' => $this->eventSource($id),
            'idempotencyKey' => 'admin-test-' . $this->uuid(),
            'properties' => (object) ['trackingSourceId' => $id, 'triggeredBy' => $this->user->getId()],
            'summary' => 'Tracking source diagnostic event',
        ]);
        return ['success' => true, 'eventId' => $event['id'], 'correlationId' => $event['correlationId'] ?? null];
    }

    /** @return array<string, mixed> */
    private function save(?string $id, stdClass $data): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $name = trim((string) ($data->name ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BadRequest('Enter a tracking source name.');
        }
        $mode = strtolower(trim((string) ($data->integrationMode ?? 'managed')));
        if (!in_array($mode, ['managed', 'external', 'necessary_only'], true)) {
            throw new BadRequest('Select a valid consent integration mode.');
        }
        $status = strtolower(trim((string) ($data->status ?? 'active')));
        if (!in_array($status, ['active', 'paused'], true)) {
            throw new BadRequest('Select a valid tracking source status.');
        }
        $origins = $this->origins($data->allowedOrigins ?? []);
        $pdo = $this->entityManager->getPDO();
        try {
            if ($id === null) {
                $id = $this->uuid();
                $statement = $pdo->prepare(
                    'INSERT INTO nexa_tracking_source (id,tenant_id,service_id,name,public_key,status,integration_mode,' .
                    'allowed_origins_json,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $statement->execute([$id, $context->tenantId, $context->serviceId, $name, bin2hex(random_bytes(24)), $status, $mode, json_encode($origins, JSON_THROW_ON_ERROR), $this->user->getId(), $this->user->getId()]);
            } else {
                $statement = $pdo->prepare(
                    'UPDATE nexa_tracking_source SET name=?,status=?,integration_mode=?,allowed_origins_json=?,modified_by_id=? ' .
                    'WHERE id=? AND tenant_id=? AND service_id=?'
                );
                $statement->execute([$name, $status, $mode, json_encode($origins, JSON_THROW_ON_ERROR), $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
                if ($statement->rowCount() === 0) {
                    $this->find($id);
                }
            }
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new BadRequest('A tracking source with this name already exists.');
            }
            throw $e;
        }

        return $this->find($id);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT s.*,COUNT(e.id) AS event_count,MAX(e.received_at) AS last_event_at ' .
            'FROM nexa_tracking_source s LEFT JOIN nexa_behavior_event e ON e.tenant_id=s.tenant_id ' .
            'AND e.service_id=s.service_id AND e.source=CONCAT(\'web.\',REPLACE(LEFT(s.id,24),\'-\',\'\')) ' .
            'WHERE s.id=? AND s.tenant_id=? AND s.service_id=? GROUP BY s.id'
        );
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFound('The tracking source is unavailable.');
        }
        return $this->payload($row);
    }

    /** @return array<string, mixed> */
    private function payload(array $row): array
    {
        $baseUrl = rtrim((string) $this->config->get('siteUrl', ''), '/');
        $key = (string) $row['public_key'];
        return [
            'id' => $row['id'], 'name' => $row['name'], 'publicKey' => $key,
            'status' => $row['status'], 'integrationMode' => $row['integration_mode'],
            'allowedOrigins' => json_decode((string) $row['allowed_origins_json'], true) ?: [],
            'eventCount' => (int) ($row['event_count'] ?? 0), 'lastEventAt' => $row['last_event_at'] ?? null,
            'createdAt' => $row['created_at'], 'modifiedAt' => $row['modified_at'],
            'embedCode' => '<script src="' . $baseUrl . '/client/custom/nexa-tracker.js" data-nexa-source-key="' . $key . '" data-nexa-consent-mode="' . $row['integration_mode'] . '" defer></script>',
        ];
    }

    /** @return list<string> */
    private function origins(mixed $input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[\r\n,]+/', $input) ?: [];
        }
        if (!is_array($input)) {
            throw new BadRequest('Enter at least one approved website origin.');
        }
        $origins = [];
        foreach ($input as $value) {
            $origin = PublicEventCollectorService::normalizeOrigin((string) $value);
            $origins[$origin] = true;
        }
        $origins = array_keys($origins);
        if ($origins === [] || count($origins) > 20) {
            throw new BadRequest('Add between 1 and 20 approved website origins.');
        }
        sort($origins);
        return $origins;
    }

    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin() || !$this->acl->checkScope('Contact')) {
            throw new Forbidden('Tenant administrator access is required.');
        }
    }

    private function eventSource(string $id): string
    {
        return 'web.' . str_replace('-', '', substr($id, 0, 24));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
