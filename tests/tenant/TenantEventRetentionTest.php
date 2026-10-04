<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Event\BehaviorEventService;
use Espo\Custom\Tools\Event\EventRetentionService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$contextStore = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$records = $container->getByClass(ServiceContainer::class);
$transactions = $entityManager->getTransactionManager();
$pdo = $entityManager->getPDO();
$tenantA = new TenantContext(
    '30000000-0000-4000-8000-000000000001',
    'isolation-alpha',
    'event-retention-test',
);
$tenantB = new TenantContext(
    '30000000-0000-4000-8000-000000000002',
    'isolation-beta',
    'event-retention-test',
);
$ingest = static function (
    TenantContextStore $store,
    InjectableFactory $factory,
    TenantContext $context,
    array $payload,
): array {
    return $store->runWith(
        $context,
        static fn (): array => $factory->create(BehaviorEventService::class)->ingest((object) $payload),
    );
};
$retention = static function (
    TenantContextStore $store,
    InjectableFactory $factory,
    TenantContext $context,
): EventRetentionService {
    return $store->runWith(
        $context,
        static fn (): EventRetentionService => $factory->create(EventRetentionService::class),
    );
};

$transactions->start();
try {
    $contact = $contextStore->runWith(
        $tenantA,
        static fn () => $records->get('Contact')->create(
            (object) ['firstName' => 'Retention', 'lastName' => 'Customer'],
            CreateParams::create(),
        ),
    );
    $identified = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'page.viewed',
        'source' => 'retention-test',
        'idempotencyKey' => 'identified-old',
        'visitorKey' => 'retention-known',
        'contactId' => $contact->getId(),
        'identityEvidence' => (object) [
            'type' => 'authenticated_session',
            'verified' => true,
            'reference' => 'retention-session',
        ],
        'occurredAt' => '2020-01-01T10:00:00Z',
        'pageUrl' => 'https://example.test/old-known',
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $anonymous = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'page.viewed',
        'source' => 'retention-test',
        'idempotencyKey' => 'anonymous-old',
        'visitorKey' => 'retention-anonymous',
        'occurredAt' => '2020-01-01T11:00:00Z',
        'pageUrl' => 'https://example.test/old-anonymous',
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $recent = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'page.viewed',
        'source' => 'retention-test',
        'idempotencyKey' => 'recent',
        'visitorKey' => 'retention-recent',
        'pageUrl' => 'https://example.test/recent',
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $otherTenant = $ingest($contextStore, $factory, $tenantB, [
        'eventType' => 'page.viewed',
        'source' => 'retention-test',
        'idempotencyKey' => 'tenant-b-old',
        'visitorKey' => 'retention-other-tenant',
        'occurredAt' => '2020-01-01T12:00:00Z',
        'pageUrl' => 'https://other.example.test/old',
        'consent' => (object) ['analytics' => 'granted'],
    ]);

    $published = $pdo->prepare(
        'UPDATE nexa_outbox_event SET published_at=NOW(6) WHERE tenant_id=? AND service_id=? AND aggregate_id IN (?,?)'
    );
    $published->execute([
        $tenantA->tenantId,
        $tenantA->serviceId,
        $identified['id'],
        $anonymous['id'],
    ]);
    $pdo->prepare(
        'INSERT INTO nexa_behavior_event_replay ' .
        '(id,tenant_id,service_id,behavior_event_id,replay_key,reason,status,correlation_id,requested_at,processed_at) ' .
        "VALUES (?,?,?,?,?,?,'completed',?, '2020-02-01 00:00:00.000000','2020-02-01 00:01:00.000000')"
    )->execute([
        $uuid(),
        $tenantA->tenantId,
        $tenantA->serviceId,
        $recent['id'],
        'completed-old-replay',
        'Retention runtime test replay',
        $uuid(),
    ]);

    $policy = $retention($contextStore, $factory, $tenantA);
    $contextStore->runWith($tenantA, static fn () => $policy->update((object) [
        'identifiedDays' => 90,
        'anonymousDays' => 30,
        'replayDays' => 30,
        'legalHold' => false,
    ]));
    $result = $contextStore->runWith($tenantA, static fn (): array => $policy->purgeNow());
    $assert($result['held'] === false, 'An inactive hold blocked retention.');
    $assert($result['eventsDeleted'] === 2, 'Due identified and anonymous events were not purged.');
    $assert($result['replaysDeleted'] === 1, 'Expired completed replay requests were not purged.');

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_behavior_event WHERE tenant_id=? AND service_id=? AND id IN (?,?,?)'
    );
    $statement->execute([
        $tenantA->tenantId,
        $tenantA->serviceId,
        $identified['id'],
        $anonymous['id'],
        $recent['id'],
    ]);
    $assert((int) $statement->fetchColumn() === 1, 'Retention removed a recent event or kept expired events.');
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? " .
        "AND source_entity_type='BehaviorEvent' AND source_entity_id=?"
    );
    $statement->execute([$tenantA->tenantId, $tenantA->serviceId, $identified['id']]);
    $assert((int) $statement->fetchColumn() === 0, 'Expired event timeline projection was not removed.');
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_outbox_event WHERE tenant_id=? AND service_id=? AND aggregate_id IN (?,?)'
    );
    $statement->execute([
        $tenantA->tenantId,
        $tenantA->serviceId,
        $identified['id'],
        $anonymous['id'],
    ]);
    $assert((int) $statement->fetchColumn() === 0, 'Published outbox payloads were not removed with expired events.');
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_behavior_event WHERE tenant_id=? AND service_id=? AND id=?'
    );
    $statement->execute([$tenantB->tenantId, $tenantB->serviceId, $otherTenant['id']]);
    $assert((int) $statement->fetchColumn() === 1, 'A tenant purge removed another tenant event.');

    $heldEvent = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'page.viewed',
        'source' => 'retention-test',
        'idempotencyKey' => 'held-old',
        'visitorKey' => 'retention-held',
        'occurredAt' => '2020-03-01T10:00:00Z',
        'pageUrl' => 'https://example.test/held',
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $pdo->prepare(
        'UPDATE nexa_outbox_event SET published_at=NOW(6) WHERE tenant_id=? AND service_id=? AND aggregate_id=?'
    )->execute([$tenantA->tenantId, $tenantA->serviceId, $heldEvent['id']]);
    $contextStore->runWith($tenantA, static fn () => $policy->update((object) [
        'identifiedDays' => 90,
        'anonymousDays' => 30,
        'replayDays' => 30,
        'legalHold' => true,
        'legalHoldReason' => 'Regulatory preservation request',
    ]));
    $heldResult = $contextStore->runWith($tenantA, static fn (): array => $policy->purgeNow());
    $assert($heldResult['held'] === true && $heldResult['eventsDeleted'] === 0, 'Legal hold did not stop purge.');
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_behavior_event WHERE tenant_id=? AND service_id=? AND id=?'
    );
    $statement->execute([$tenantA->tenantId, $tenantA->serviceId, $heldEvent['id']]);
    $assert((int) $statement->fetchColumn() === 1, 'A held event was removed.');

    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id=? AND service_id=? " .
        "AND action IN ('event.retention.policy.updated','event.retention.purge.completed')"
    );
    $statement->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $statement->fetchColumn() >= 3, 'Retention policy and purge evidence were not audited.');

    echo "Tenant event retention tests passed.\n";
} finally {
    $transactions->rollback();
}
