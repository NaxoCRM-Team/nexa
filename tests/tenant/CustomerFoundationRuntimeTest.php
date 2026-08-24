<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Custom\Tools\Customer\CustomerFoundationQueryService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$id = static fn (string $prefix): string => substr($prefix . bin2hex(random_bytes(8)), 0, 17);
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'customer-foundation-runtime-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'customer-foundation-runtime-test');

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$contextStore = $container->getByClass(TenantContextStore::class);
$platform = $container->getByClass(PlatformExecutionGateway::class);
$queryService = $container->getByClass(InjectableFactory::class)->create(CustomerFoundationQueryService::class);
$pdo = $entityManager->getPDO();
$ids = [
    'accountA' => $id('nxfaccta'), 'accountB' => $id('nxfacctb'),
    'contactA' => $id('nxfconta'), 'contactB' => $id('nxfcontb'),
    'noteA' => $id('nxfnotea'), 'noteB' => $id('nxfnoteb'),
];

$pdo->beginTransaction();
try {
    $contextStore->runWith($tenantA, function () use ($entityManager, $ids): void {
        $entityManager->createEntity('Account', [
            'id' => $ids['accountA'], 'name' => 'Foundation Account A', 'lifecycleStage' => 'Lead',
        ]);
        $entityManager->createEntity('Contact', [
            'id' => $ids['contactA'], 'firstName' => 'Foundation', 'lastName' => 'A',
            'emailAddress' => 'foundation-a@example.test',
            'accountId' => $ids['accountA'], 'lifecycleStage' => 'Lead',
        ]);
        $entityManager->createEntity('Note', [
            'id' => $ids['noteA'], 'type' => 'Post', 'post' => 'Tenant A note',
            'parentType' => 'Contact', 'parentId' => $ids['contactA'],
        ]);

        $contact = $entityManager->getRDBRepository('Contact')->getById($ids['contactA']);
        $contact?->set('lifecycleStage', 'Customer');
        if ($contact) {
            $entityManager->saveEntity($contact);
        }
    });

    $contextStore->runWith($tenantB, function () use ($entityManager, $ids): void {
        $entityManager->createEntity('Account', [
            'id' => $ids['accountB'], 'name' => 'Foundation Account B', 'lifecycleStage' => 'Lead',
        ]);
        $entityManager->createEntity('Contact', [
            'id' => $ids['contactB'], 'firstName' => 'Foundation', 'lastName' => 'B',
            'emailAddress' => 'foundation-b@example.test',
            'accountId' => $ids['accountB'], 'lifecycleStage' => 'Lead',
        ]);
        $entityManager->createEntity('Note', [
            'id' => $ids['noteB'], 'type' => 'Post', 'post' => 'Tenant B note',
            'parentType' => 'Contact', 'parentId' => $ids['contactB'],
        ]);
    });

    $counts = $platform->run('verify customer foundation projections', function () use ($pdo, $ids, $tenantA, $tenantB): array {
        $result = [];
        foreach (['a' => $tenantA, 'b' => $tenantB] as $key => $tenant) {
            $contactId = $ids['contact' . strtoupper($key)];
            $accountId = $ids['account' . strtoupper($key)];
            $statement = $pdo->prepare(
                'SELECT ' .
                '(SELECT COUNT(*) FROM nexa_lifecycle_assignment WHERE tenant_id = ? AND entity_id IN (?, ?)) AS lifecycle_count, ' .
                '(SELECT COUNT(*) FROM nexa_lifecycle_transition t INNER JOIN nexa_lifecycle_assignment a ' .
                'ON a.id = t.lifecycle_assignment_id AND a.tenant_id = t.tenant_id ' .
                'WHERE t.tenant_id = ? AND a.entity_id IN (?, ?)) AS transition_count, ' .
                '(SELECT COUNT(*) FROM nexa_identity_link WHERE tenant_id = ? AND contact_id = ?) AS identity_count, ' .
                '(SELECT COUNT(*) FROM nexa_relationship_edge WHERE tenant_id = ? AND source_entity_id = ? AND target_entity_id = ? AND deleted_at IS NULL) AS relationship_count, ' .
                '(SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id = ? AND (contact_id = ? OR account_id = ?)) AS timeline_count, ' .
                '(SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id = ? AND subject_id IN (?, ?)) AS audit_count, ' .
                '(SELECT COUNT(*) FROM nexa_outbox_event WHERE tenant_id = ? AND aggregate_id IN (?, ?)) AS outbox_count'
            );
            $statement->execute([
                $tenant->tenantId, $contactId, $accountId,
                $tenant->tenantId, $contactId, $accountId,
                $tenant->tenantId, $contactId,
                $tenant->tenantId, $contactId, $accountId,
                $tenant->tenantId, $contactId, $accountId,
                $tenant->tenantId, $contactId, $accountId,
                $tenant->tenantId, $contactId, $accountId,
            ]);
            $result[$key] = $statement->fetch(\PDO::FETCH_ASSOC);
        }
        return $result;
    });

    foreach (['a', 'b'] as $key) {
        $assert((int) $counts[$key]['lifecycle_count'] === 2, "Tenant {$key} lifecycle assignments are incomplete.");
        $assert((int) $counts[$key]['transition_count'] >= 2, "Tenant {$key} lifecycle history is incomplete.");
        $assert((int) $counts[$key]['identity_count'] >= 1, "Tenant {$key} identity projection is missing.");
        $assert((int) $counts[$key]['relationship_count'] >= 1, "Tenant {$key} relationship projection is missing.");
        $assert((int) $counts[$key]['timeline_count'] >= 3, "Tenant {$key} unified timeline is incomplete.");
        $assert((int) $counts[$key]['audit_count'] >= 2, "Tenant {$key} audit projection is incomplete.");
        $assert((int) $counts[$key]['outbox_count'] >= 2, "Tenant {$key} outbox projection is incomplete.");
    }

    $snapshot = $contextStore->runWith(
        $tenantA,
        fn (): array => $queryService->getSnapshot('Contact', $ids['contactA'])
    );
    $assert($snapshot['tenantId'] === $tenantA->tenantId, 'The snapshot returned the wrong tenant identity.');
    $assert(count($snapshot['identities']) >= 1, 'The snapshot omitted Contact identities.');
    $assert(count($snapshot['relationships']) >= 1, 'The snapshot omitted visible relationships.');
    $assert(count($snapshot['lifecycle']['transitions']) >= 2, 'The snapshot omitted lifecycle history.');
    $assert(count($snapshot['timeline']) >= 3, 'The snapshot omitted timeline events.');

    $contextStore->runWith($tenantA, function () use ($entityManager, $ids, $assert): void {
        $assert($entityManager->getRDBRepository('Contact')->getById($ids['contactB']) === null, 'Tenant A can read tenant B customer data.');
    });

    echo "Customer foundation runtime isolation tests passed.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
