<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\CaseService\CaseSlaService;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'case-sla-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'case-sla-test');
$suffix = bin2hex(random_bytes(4));
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$service = $container->getByClass(InjectableFactory::class)->create(CaseSlaService::class);
$pdo = $entityManager->getPDO();
$pdo->beginTransaction();

try {
    $alphaCase = $store->runWith($tenantA, function () use ($entityManager, $suffix) {
        return $entityManager->createEntity('Case', [
            'name' => "Alpha service request {$suffix}", 'status' => 'New', 'priority' => 'Urgent', 'category' => 'Technical',
        ]);
    });
    $betaCase = $store->runWith($tenantB, function () use ($entityManager, $suffix) {
        return $entityManager->createEntity('Case', [
            'name' => "Beta service request {$suffix}", 'status' => 'New', 'priority' => 'Low', 'category' => 'Billing',
        ]);
    });
    $assert($alphaCase->get('slaStatus') === 'Running', 'New Case did not start its SLA.');
    $assert((bool) $alphaCase->get('slaPolicyId') && (bool) $alphaCase->get('firstResponseDueAt') && (bool) $alphaCase->get('resolutionDueAt'), 'New Case SLA deadlines are incomplete.');
    $ownership = $pdo->prepare('SELECT tenant_id,service_id FROM `case` WHERE id=?');
    $ownership->execute([$alphaCase->getId()]); $alphaOwnership = $ownership->fetch(PDO::FETCH_ASSOC);
    $ownership->execute([$betaCase->getId()]); $betaOwnership = $ownership->fetch(PDO::FETCH_ASSOC);
    $assert($alphaOwnership['tenant_id'] === $tenantA->tenantId && $alphaOwnership['service_id'] === $tenantA->serviceId, 'Case write is not tenant/service owned.');
    $assert($betaOwnership['tenant_id'] === $tenantB->tenantId, 'Second tenant Case ownership is incorrect.');

    $store->runWith($tenantA, function () use ($entityManager, $alphaCase): void {
        $alphaCase->set('status', 'Pending'); $entityManager->saveEntity($alphaCase);
    });
    $assert($alphaCase->get('slaStatus') === 'Paused' && (bool) $alphaCase->get('slaPausedAt'), 'Pending Case did not pause its SLA.');

    $response = $store->runWith($tenantA, fn (): array => $service->recordResponse($alphaCase->getId()));
    $assert((bool) $response['firstRespondedAt'], 'First-response action did not persist its timestamp.');

    $crossTenantBlocked = false;
    try { $store->runWith($tenantB, fn (): array => $service->summaryForCase($alphaCase->getId())); }
    catch (Throwable) { $crossTenantBlocked = true; }
    $assert($crossTenantBlocked, 'Tenant B could read Tenant A Case SLA context.');

    $alphaVisible = $store->runWith($tenantA, fn () => $entityManager->getRDBRepository('Case')->getById($alphaCase->getId()));
    $betaLeak = $store->runWith($tenantB, fn () => $entityManager->getRDBRepository('Case')->getById($alphaCase->getId()));
    $assert($alphaVisible !== null && $betaLeak === null, 'Native Case ORM isolation failed.');
    echo "Tenant Case SLA isolation tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
