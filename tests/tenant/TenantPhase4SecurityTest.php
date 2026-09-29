<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\LandingPage\LandingPageService;
use Espo\Custom\Tools\PublicAccess\PublicRateLimitExceeded;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'phase4-security');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'phase4-security');
$transaction = $entityManager->getTransactionManager();
$transaction->start();

try {
    $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
    $_SERVER['HTTP_USER_AGENT'] = 'Nexa Phase 4 security test';
    $scope = 'phase4-test:' . bin2hex(random_bytes(6));

    $store->runWith($tenantA, function () use ($factory, $tenantA, $scope): void {
        $limiter = $factory->create(PublicRequestLimiter::class);
        $limiter->enforce($tenantA->tenantId, $tenantA->serviceId, $scope, 2, 600);
        $limiter->enforce($tenantA->tenantId, $tenantA->serviceId, $scope, 2, 600);
    });

    $blocked = false;
    try {
        $store->runWith($tenantA, fn () => $factory->create(PublicRequestLimiter::class)
            ->enforce($tenantA->tenantId, $tenantA->serviceId, $scope, 2, 600));
    } catch (PublicRateLimitExceeded $e) {
        $blocked = $e->getCode() === 429;
    }
    $assert($blocked, 'The public limiter did not return HTTP 429 after the configured threshold.');

    $store->runWith($tenantB, fn () => $factory->create(PublicRequestLimiter::class)
        ->enforce($tenantB->tenantId, $tenantB->serviceId, $scope, 2, 600));

    $landing = $store->runWith($tenantA, fn () => $factory->create(LandingPageService::class));
    $signature = $landing->signClickDestination(str_repeat('a', 48), 'signed-page', 'hero', 'https://example.test/demo');
    $landing->verifyClickDestination(str_repeat('a', 48), 'signed-page', 'hero', 'https://example.test/demo', $signature);
    $tamperingRejected = false;
    try {
        $landing->verifyClickDestination(str_repeat('a', 48), 'signed-page', 'hero', 'https://evil.example/', $signature);
    } catch (\Espo\Core\Exceptions\BadRequest) {
        $tamperingRejected = true;
    }
    $assert($tamperingRejected, 'A signed landing click could be redirected to a tampered destination.');

    $count = $entityManager->getPDO()->prepare('SELECT COUNT(*) FROM nexa_public_rate_limit WHERE scope_key=? AND tenant_id=? AND service_id=?');
    $count->execute([$scope, $tenantB->tenantId, $tenantB->serviceId]);
    $assert((int) $count->fetchColumn() === 1, 'Tenant B did not receive an independent rate-limit bucket.');

    echo "Tenant Phase 4 security tests passed.\n";
} finally {
    if ($transaction->isStarted()) {
        $transaction->rollback();
    }
}
