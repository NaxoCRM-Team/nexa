<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';
use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Asset\AssetWorkspaceService;
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-asset-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-asset-test');
$application = new Application(); $application->setupSystemUser(); $container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class); $store = $container->getByClass(TenantContextStore::class); $factory = $container->getByClass(InjectableFactory::class); $pdo = $entityManager->getPDO(); $transaction = $entityManager->getTransactionManager();
$transaction->start();
try {
    $all = (object) ['search' => '', 'status' => 'active', 'type' => 'all', 'offset' => 0, 'limit' => 50];
    $alpha = $store->runWith($tenantA, fn (): array => $factory->create(AssetWorkspaceService::class)->getWorkspace($all));
    $assert(count($alpha['list']) > 0, 'Tenant A needs one native Document or reusable Attachment fixture for the asset isolation test.');
    $assetId = (string) $alpha['list'][0]['id'];
    $query = (object) ['search' => (string) $alpha['list'][0]['name'], 'status' => 'active', 'type' => 'all', 'offset' => 0, 'limit' => 50];
    $beta = $store->runWith($tenantB, fn (): array => $factory->create(AssetWorkspaceService::class)->getWorkspace($query));
    $assert(count(array_filter($beta['list'], static fn (array $item): bool => $item['id'] === $assetId)) === 0, 'Tenant B can see Tenant A assets.');
    $store->runWith($tenantA, fn (): array => $factory->create(AssetWorkspaceService::class)->setArchived($assetId, true));
    $status = $pdo->prepare('SELECT status FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=?'); $status->execute([$assetId, $tenantA->tenantId, $tenantA->serviceId]); $assert($status->fetchColumn() === 'archived', 'Asset archive state was not recorded.');
    $store->runWith($tenantA, fn (): array => $factory->create(AssetWorkspaceService::class)->setArchived($assetId, false));
    $status->execute([$assetId, $tenantA->tenantId, $tenantA->serviceId]); $assert($status->fetchColumn() === 'active', 'Asset restore state was not recorded.');
    $transaction->rollback();
} catch (Throwable $e) {
    if ($transaction->isStarted()) $transaction->rollback();
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    throw $e;
}
echo "Tenant asset workspace tests passed.\n";
