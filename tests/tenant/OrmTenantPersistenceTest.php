<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\TenantResolver;
use Espo\Core\ORM\EntityManager;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tenantA = new TenantContext(
    '30000000-0000-4000-8000-000000000001',
    'isolation-alpha',
    'orm-persistence-test',
);
$tenantB = new TenantContext(
    '30000000-0000-4000-8000-000000000002',
    'isolation-beta',
    'orm-persistence-test',
);
$testIds = ['nexaormtenant0001', 'nexaormtenant0002'];
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$contextStore = $container->getByClass(TenantContextStore::class);
$platform = $container->getByClass(PlatformExecutionGateway::class);
$resolver = $container->getByClass(TenantResolver::class);
$resolvedA = $resolver->resolveHost('tenant-a.localhost');
$resolvedB = $resolver->resolveHost('tenant-b.localhost');
$assert($resolvedA?->tenantId === $tenantA->tenantId && $resolvedA->serviceId === $tenantA->serviceId, 'Tenant A host discovery did not resolve complete ownership.');
$assert($resolvedB?->tenantId === $tenantB->tenantId && $resolvedB->serviceId === $tenantB->serviceId, 'Tenant B host discovery did not resolve complete ownership.');
$pdo = $entityManager->getPDO();

$cleanup = static function () use ($pdo, $testIds): void {
    $placeholders = implode(',', array_fill(0, count($testIds), '?'));
    $pdo->prepare("DELETE t FROM nexa_lifecycle_transition t INNER JOIN nexa_lifecycle_assignment a ON a.id = t.lifecycle_assignment_id AND a.tenant_id = t.tenant_id WHERE a.entity_id IN ({$placeholders})")->execute($testIds);
    $pdo->prepare("DELETE FROM nexa_lifecycle_assignment WHERE entity_id IN ({$placeholders})")->execute($testIds);
    $pdo->prepare("DELETE FROM nexa_timeline_event WHERE source_entity_id IN ({$placeholders}) OR account_id IN ({$placeholders})")->execute([...$testIds, ...$testIds]);
    $pdo->prepare("DELETE FROM nexa_relationship_edge WHERE source_entity_id IN ({$placeholders}) OR target_entity_id IN ({$placeholders})")->execute([...$testIds, ...$testIds]);
    $pdo->prepare("DELETE FROM nexa_audit_event WHERE subject_id IN ({$placeholders})")->execute($testIds);
    $pdo->prepare("DELETE FROM nexa_outbox_event WHERE aggregate_id IN ({$placeholders})")->execute($testIds);
    $pdo->prepare("DELETE FROM account WHERE id IN ({$placeholders})")->execute($testIds);
};

$platform->run('prepare ORM tenant persistence test', $cleanup);

try {
    $accountA = $contextStore->runWith($tenantA, fn () => $entityManager->createEntity('Account', [
        'id' => $testIds[0],
        'name' => 'ORM tenant A record',
    ]));
    $accountB = $contextStore->runWith($tenantB, fn () => $entityManager->createEntity('Account', [
        'id' => $testIds[1],
        'name' => 'ORM tenant B record',
    ]));

    $assert($accountA->getId() === $testIds[0], 'Tenant A ORM record ID changed unexpectedly.');
    $assert($accountB->getId() === $testIds[1], 'Tenant B ORM record ID changed unexpectedly.');

    $rows = $platform->run('verify ORM ownership columns', function () use ($pdo, $testIds): array {
        $placeholders = implode(',', array_fill(0, count($testIds), '?'));
        $statement = $pdo->prepare(
            "SELECT id, tenant_id, service_id, name FROM account WHERE id IN ({$placeholders}) ORDER BY id"
        );
        $statement->execute($testIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    });

    $assert(count($rows) === 2, 'ORM did not persist both synthetic tenant records.');
    $byId = array_column($rows, null, 'id');
    $assert($byId[$testIds[0]]['tenant_id'] === $tenantA->tenantId, 'Tenant A ID was not injected on create.');
    $assert($byId[$testIds[0]]['service_id'] === $tenantA->serviceId, 'Tenant A service was not injected on create.');
    $assert($byId[$testIds[1]]['tenant_id'] === $tenantB->tenantId, 'Tenant B ID was not injected on create.');
    $assert($byId[$testIds[1]]['service_id'] === $tenantB->serviceId, 'Tenant B service was not injected on create.');

    $contextStore->runWith($tenantA, function () use ($entityManager, $assert, $testIds): void {
        $repository = $entityManager->getRepository('Account');
        $assert($repository->getById($testIds[0]) !== null, 'Tenant A cannot read its own ORM record.');
        $assert($repository->getById($testIds[1]) === null, 'Tenant A can read tenant B through the ORM.');

        $query = $entityManager->getQueryBuilder()->update()
            ->in('Account')
            ->set(['name' => 'Forbidden cross-tenant update'])
            ->where(['id' => $testIds[1]])
            ->build();
        $statement = $entityManager->getQueryExecutor()->execute($query);
        $assert($statement->rowCount() === 0, 'Tenant A updated tenant B through the ORM.');

        $query = $entityManager->getQueryBuilder()->delete()
            ->from('Account')
            ->where(['id' => $testIds[1]])
            ->build();
        $statement = $entityManager->getQueryExecutor()->execute($query);
        $assert($statement->rowCount() === 0, 'Tenant A deleted tenant B through the ORM.');
    });

    $tenantBName = $platform->run('verify cross-tenant write protection', function () use ($pdo, $testIds): string {
        $statement = $pdo->prepare('SELECT name FROM account WHERE id = ?');
        $statement->execute([$testIds[1]]);

        return (string) $statement->fetchColumn();
    });
    $assert($tenantBName === 'ORM tenant B record', 'Tenant B data changed during tenant A operations.');

    echo "ORM tenant persistence tests passed.\n";
} finally {
    $platform->run('clean up ORM tenant persistence test', $cleanup);
}
