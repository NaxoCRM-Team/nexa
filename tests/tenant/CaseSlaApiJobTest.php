<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Api\RequestWrapper;
use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Jobs\MonitorCaseSla;
use Espo\Custom\Tools\CaseService\Api\GetCaseSla;
use Espo\Custom\Tools\CaseService\Api\GetSlaPolicies;
use Espo\Custom\Tools\CaseService\Api\PostCaseResponse;
use Espo\Custom\Tools\CaseService\Api\PutSlaPolicies;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$decode = static fn ($response): array => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
$request = static function (string $method, array $route = [], ?array $body = null): RequestWrapper {
    $psr = (new ServerRequestFactory())->createServerRequest($method, '/api/v1/Nexa/cases');
    if ($body !== null) {
        $psr = $psr
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }
    return new RequestWrapper($psr, '', $route);
};

$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'case-api-job-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'case-api-job-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$factory = $container->getByClass(InjectableFactory::class);
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$pdo = $entityManager->getPDO();
$pdo->beginTransaction();

try {
    $pdo->prepare('DELETE FROM nexa_case_sla_policy WHERE tenant_id=? AND service_id=?')
        ->execute([$tenantA->tenantId, $tenantA->serviceId]);

    $alphaCase = $store->runWith($tenantA, fn () => $entityManager->createEntity('Case', [
        'name' => 'Phase 3 overdue API Case', 'status' => 'New', 'priority' => 'Normal', 'category' => 'Technical',
    ]));
    $policyCount = $pdo->prepare('SELECT COUNT(*) FROM nexa_case_sla_policy WHERE tenant_id=? AND service_id=? AND is_active=1');
    $policyCount->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $policyCount->fetchColumn() === 4, 'A tenant created after migration did not receive default SLA policies lazily.');

    $betaCase = $store->runWith($tenantB, fn () => $entityManager->createEntity('Case', [
        'name' => 'Phase 3 foreign tenant Case', 'status' => 'New', 'priority' => 'Normal', 'category' => 'Billing',
    ]));
    foreach ([[$tenantA, $alphaCase], [$tenantB, $betaCase]] as [$tenant, $case]) {
        $store->runWith($tenant, function () use ($entityManager, $case): void {
            $case->set('slaStatus', 'Running');
            $case->set('resolutionDueAt', gmdate('Y-m-d H:i:s', time() - 3600));
            $entityManager->saveEntity($case);
        });
    }

    $getCase = $factory->create(GetCaseSla::class);
    $caseSummary = $store->runWith(
        $tenantA,
        fn (): array => $decode($getCase->process($request('GET', ['id' => $alphaCase->getId()])))
    );
    $assert(($caseSummary['id'] ?? null) === $alphaCase->getId(), 'GET Case SLA action returned the wrong Case.');

    $getPolicies = $factory->create(GetSlaPolicies::class);
    $policies = $store->runWith($tenantA, fn (): array => $decode($getPolicies->process($request('GET'))));
    $assert(count($policies['list'] ?? []) === 4, 'GET SLA policies action omitted tenant policies.');

    $putPolicies = $factory->create(PutSlaPolicies::class);
    $updated = $store->runWith($tenantA, fn (): array => $decode($putPolicies->process($request('PUT', [], [
        'policies' => [
            ['name' => 'Standard support', 'priority' => 'Normal', 'firstResponseMinutes' => 120, 'resolutionMinutes' => 1440, 'isDefault' => true],
            ['name' => 'Priority support', 'priority' => 'Urgent', 'firstResponseMinutes' => 30, 'resolutionMinutes' => 240, 'isDefault' => false],
        ],
    ]))));
    $assert(count($updated['list'] ?? []) === 2, 'PUT SLA policies action did not persist the submitted tenant catalogue.');

    $postResponse = $factory->create(PostCaseResponse::class);
    $response = $store->runWith(
        $tenantA,
        fn (): array => $decode($postResponse->process($request('POST', ['id' => $alphaCase->getId()])))
    );
    $assert(!empty($response['firstRespondedAt']), 'POST Case response action did not record first response.');

    $job = $factory->create(MonitorCaseSla::class);
    $store->runWith($tenantA, fn () => $job->run());
    $alphaCase = $store->runWith($tenantA, fn () => $entityManager->getRDBRepository('Case')->getById($alphaCase->getId()));
    $betaCase = $store->runWith($tenantB, fn () => $entityManager->getRDBRepository('Case')->getById($betaCase->getId()));
    $assert($alphaCase?->get('slaStatus') === 'Breached' && (int) $alphaCase->get('escalationLevel') >= 1, 'SLA monitor did not breach and escalate Tenant A Case.');
    $assert($betaCase?->get('slaStatus') === 'Running', 'Tenant A SLA monitor changed Tenant B Case.');

    echo "Case SLA API and background-job tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
