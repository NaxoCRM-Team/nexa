<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Event\BehaviorEventReplayService;
use Espo\Custom\Tools\Event\BehaviorEventService;

$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);
$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$records = $container->getByClass(ServiceContainer::class);
$transactionManager = $entityManager->getTransactionManager();
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'event-replay-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'event-replay-test');

$transactionManager->start();
try {
    $contactA = $store->runWith($tenantA, fn () => $records->get('Contact')->create(
        (object) ['firstName' => 'Replay', 'lastName' => 'Primary'],
        CreateParams::create(),
    ));
    $contactB = $store->runWith($tenantA, fn () => $records->get('Contact')->create(
        (object) ['firstName' => 'Replay', 'lastName' => 'Conflict'],
        CreateParams::create(),
    ));

    $event = $store->runWith($tenantA, fn () => $factory->create(BehaviorEventService::class)->ingest((object) [
        'eventType' => 'form.submitted',
        'source' => 'replay-test',
        'idempotencyKey' => 'replay-source-event',
        'visitorKey' => 'replay-visitor',
        'contactId' => $contactA->getId(),
        'identityEvidence' => (object) [
            'type' => 'form_submission',
            'verified' => true,
            'reference' => 'replay-form-submission',
        ],
    ]));

    $request = (object) [
        'replayKey' => 'support-replay-001',
        'reason' => 'Recover a downstream consumer after a verified outage.',
    ];
    $first = $store->runWith($tenantA, fn () => $factory->create(BehaviorEventReplayService::class)
        ->request($event['id'], $request));
    $retry = $store->runWith($tenantA, fn () => $factory->create(BehaviorEventReplayService::class)
        ->request($event['id'], $request));

    $assert(!$first['duplicate'], 'The first replay request was incorrectly marked as a duplicate.');
    $assert($retry['duplicate'] && $retry['id'] === $first['id'], 'Replay retries must return the original request.');

    $pdo = $entityManager->getPDO();
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_behavior_event_replay ' .
        'WHERE tenant_id=? AND service_id=? AND behavior_event_id=?'
    );
    $count->execute([$tenantA->tenantId, $tenantA->serviceId, $event['id']]);
    $assert((int) $count->fetchColumn() === 1, 'Replay idempotency created duplicate requests.');

    $outbox = $pdo->prepare(
        "SELECT COUNT(*) FROM nexa_outbox_event WHERE tenant_id=? AND service_id=? " .
        "AND aggregate_id=? AND event_type='behavior.event.replay.requested'"
    );
    $outbox->execute([$tenantA->tenantId, $tenantA->serviceId, $event['id']]);
    $assert((int) $outbox->fetchColumn() === 1, 'Replay did not publish exactly one outbox event.');

    $crossTenantDenied = false;
    try {
        $store->runWith($tenantB, fn () => $factory->create(BehaviorEventReplayService::class)
            ->request($event['id'], (object) [
                'replayKey' => 'cross-tenant-replay',
                'reason' => 'This replay must not cross the tenant boundary.',
            ]));
    } catch (NotFound) {
        $crossTenantDenied = true;
    }
    $assert($crossTenantDenied, 'Another tenant could request replay of the event.');

    $identityConflictDenied = false;
    try {
        $store->runWith($tenantA, fn () => $factory->create(BehaviorEventService::class)->ingest((object) [
            'eventType' => 'form.submitted',
            'source' => 'replay-test',
            'idempotencyKey' => 'conflicting-identity-event',
            'visitorKey' => 'replay-visitor',
            'contactId' => $contactB->getId(),
            'identityEvidence' => (object) [
                'type' => 'form_submission',
                'verified' => true,
                'reference' => 'conflicting-form-submission',
            ],
        ]));
    } catch (BadRequest) {
        $identityConflictDenied = true;
    }
    $assert($identityConflictDenied, 'A visitor identity could be reassigned to a conflicting Contact.');

    echo "Tenant behavior event replay and failure tests passed.\n";
} finally {
    $transactionManager->rollback();
}

