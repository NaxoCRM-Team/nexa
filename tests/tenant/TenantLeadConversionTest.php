<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Lead\LeadConversionService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$id = static fn (string $prefix): string => substr($prefix . bin2hex(random_bytes(8)), 0, 17);
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'lead-conversion-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'lead-conversion-test');
$ids = ['leadA' => $id('nxlca'), 'leadB' => $id('nxlcb'), 'contact' => $id('nxlcc'), 'account' => $id('nxlcd')];

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$contextStore = $container->getByClass(TenantContextStore::class);
$service = $container->getByClass(InjectableFactory::class)->create(LeadConversionService::class);
$pdo = $entityManager->getPDO();
$pdo->beginTransaction();

try {
    $contextStore->runWith($tenantA, function () use ($entityManager, $ids): void {
        $entityManager->createEntity('Lead', [
            'id' => $ids['leadA'], 'firstName' => 'Tenant', 'lastName' => 'Lead',
            'accountName' => 'Tenant A Company', 'status' => 'New', 'source' => 'Web Site',
            'rating' => 'Hot', 'leadScore' => 82, 'lifecycleStage' => 'MarketingQualifiedLead',
            'marketingStatus' => 'Marketing', 'legalBasis' => 'FreelyGivenConsent',
        ]);
    });
    $contextStore->runWith($tenantB, function () use ($entityManager, $ids): void {
        $entityManager->createEntity('Lead', ['id' => $ids['leadB'], 'firstName' => 'Foreign', 'lastName' => 'Lead']);
    });

    $contextStore->runWith($tenantA, function () use ($service, $entityManager, $pdo, $tenantA, $ids): void {
        $service->convert($ids['leadA'], function () use ($entityManager, $pdo, $tenantA, $ids): object {
            $entityManager->createEntity('Contact', ['id' => $ids['contact'], 'firstName' => 'Tenant', 'lastName' => 'Lead']);
            $entityManager->createEntity('Account', ['id' => $ids['account'], 'name' => 'Tenant A Company']);
            $update = $pdo->prepare(
                "UPDATE lead SET status='Converted', converted_at=CURRENT_TIMESTAMP, created_contact_id=?, created_account_id=? " .
                'WHERE id=? AND tenant_id=? AND service_id=?'
            );
            $update->execute([$ids['contact'], $ids['account'], $ids['leadA'], $tenantA->tenantId, $tenantA->serviceId]);
            return (object) ['id' => $ids['leadA']];
        });
    });

    $contact = $pdo->prepare('SELECT lead_score,lifecycle_stage,marketing_status,legal_basis FROM contact WHERE id=? AND tenant_id=? AND service_id=?');
    $contact->execute([$ids['contact'], $tenantA->tenantId, $tenantA->serviceId]);
    $contact = $contact->fetch(PDO::FETCH_ASSOC);
    $assert((int) ($contact['lead_score'] ?? -1) === 82, 'Lead score was not preserved on the converted Contact.');
    $assert(($contact['lifecycle_stage'] ?? null) === 'MarketingQualifiedLead', 'Lifecycle stage was not preserved.');
    $assert(($contact['marketing_status'] ?? null) === 'Marketing', 'Marketing status was not preserved.');
    $assert(($contact['legal_basis'] ?? null) === 'FreelyGivenConsent', 'Consent basis was not preserved.');

    $ledger = $pdo->prepare('SELECT COUNT(*) FROM nexa_lead_conversion WHERE tenant_id=? AND service_id=? AND lead_id=? AND contact_id=? AND account_id=?');
    $ledger->execute([$tenantA->tenantId, $tenantA->serviceId, $ids['leadA'], $ids['contact'], $ids['account']]);
    $assert((int) $ledger->fetchColumn() === 1, 'The tenant-scoped conversion ledger was not recorded.');

    $blocked = false;
    try {
        $contextStore->runWith($tenantA, fn () => $service->convert($ids['leadB'], fn (): object => (object) []));
    } catch (NotFound) {
        $blocked = true;
    }
    $assert($blocked, 'Tenant A could convert Tenant B\'s Lead.');
    echo "Two-tenant Lead conversion tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
