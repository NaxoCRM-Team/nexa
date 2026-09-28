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
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-segment-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-segment-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$recordServices = $container->getByClass(ServiceContainer::class);
$pdo = $entityManager->getPDO();
$transactionManager = $entityManager->getTransactionManager();

$transactionManager->start();
try {
    $contactA = $store->runWith($tenantA, fn () => $recordServices->get('Contact')->create((object) [
        'firstName' => 'Alpha', 'lastName' => 'Segment', 'addressCountry' => 'Nexa Segment Test Country',
        'marketingStatus' => 'Non-Marketing',
    ], CreateParams::create()));
    $contactB = $store->runWith($tenantB, fn () => $recordServices->get('Contact')->create((object) [
        'firstName' => 'Beta', 'lastName' => 'Segment', 'addressCountry' => 'Nexa Segment Test Country',
        'marketingStatus' => 'Non-Marketing',
    ], CreateParams::create()));

    $created = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->save((object) [
        'name' => 'Alpha country prospects', 'description' => 'Tenant A dynamic audience.', 'type' => 'dynamic', 'matchMode' => 'all',
        'rules' => [(object) ['field' => 'country', 'operator' => 'equals', 'value' => 'Nexa Segment Test Country']],
    ]));
    $segmentId = (string) ($created['id'] ?? '');
    $assert($segmentId !== '', 'Creating a dynamic segment did not return its native Target List ID.');

    $native = $pdo->prepare('SELECT tenant_id,service_id,name FROM target_list WHERE id=?');
    $native->execute([$segmentId]);
    $nativeRow = $native->fetch(PDO::FETCH_ASSOC);
    $assert($nativeRow && $nativeRow['tenant_id'] === $tenantA->tenantId && $nativeRow['service_id'] === $tenantA->serviceId, 'The native Target List was not created in Tenant A.');

    $membership = $pdo->prepare('SELECT contact_id,tenant_id,service_id FROM contact_target_list WHERE target_list_id=? AND deleted=0');
    $membership->execute([$segmentId]);
    $members = $membership->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($members) === 1 && $members[0]['contact_id'] === $contactA->getId(), 'Dynamic recalculation did not include the matching Tenant A contact.');
    $assert($members[0]['tenant_id'] === $tenantA->tenantId && $members[0]['service_id'] === $tenantA->serviceId, 'Dynamic membership lacks Tenant A ownership.');
    $assert($members[0]['contact_id'] !== $contactB->getId(), 'Tenant B contact leaked into Tenant A segment.');

    $exported = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->export($segmentId));
    $assert(count($exported) === 1 && $exported[0]['id'] === $contactA->getId(), 'Segment export did not remain tenant scoped.');

    $snapshot = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->save((object) [
        'name' => 'Alpha fixed country snapshot', 'type' => 'static', 'snapshotFromRules' => true,
        'folder' => 'Sales audiences', 'accessLevel' => 'admins', 'matchMode' => 'all',
        'rules' => [(object) ['field' => 'country', 'operator' => 'equals', 'value' => 'Nexa Segment Test Country']],
        'exclusionRules' => [],
    ]));
    $snapshotCount = $pdo->prepare('SELECT COUNT(*) FROM contact_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0');
    $snapshotCount->execute([$tenantA->tenantId, $tenantA->serviceId, $snapshot['id']]);
    $assert((int) $snapshotCount->fetchColumn() === 1, 'Static filtered snapshot did not capture its initial matching contact.');

    $copy = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->duplicate($segmentId));
    $assert($copy['id'] !== $segmentId, 'Cloning a segment did not create a new native Target List.');

    $workspaceB = $store->runWith($tenantB, fn (): array => $factory->create(SegmentWorkspaceService::class)->getWorkspace());
    $assert(count(array_filter($workspaceB['segments'], static fn (array $item): bool => $item['id'] === $segmentId)) === 0, 'Tenant B can see Tenant A segment.');

    $store->runWith($tenantA, fn () => $recordServices->get('Contact')->update($contactA->getId(), (object) ['addressCountry' => 'Different Country'], \Espo\Core\Record\UpdateParams::create()));
    $result = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->recalculate($segmentId));
    $assert($result['memberCount'] === 0 && $result['exitedCount'] === 1, 'Dynamic recalculation did not remove a contact that stopped matching.');
    $events = $pdo->prepare("SELECT event_type,contact_id FROM nexa_segment_membership_event WHERE tenant_id=? AND service_id=? AND target_list_id=? ORDER BY occurred_at");
    $events->execute([$tenantA->tenantId, $tenantA->serviceId, $segmentId]);
    $types = array_column($events->fetchAll(PDO::FETCH_ASSOC), 'event_type');
    $assert(in_array('entered', $types, true) && in_array('exited', $types, true), 'Segment membership history did not record entry and exit.');

    $snapshotCount->execute([$tenantA->tenantId, $tenantA->serviceId, $snapshot['id']]);
    $assert((int) $snapshotCount->fetchColumn() === 1, 'A static snapshot changed after the source Contact stopped matching.');

    echo "Tenant Lists and Segments workspace tests passed.\n";
} finally {
    $transactionManager->rollback();
}
