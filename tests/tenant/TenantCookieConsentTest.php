<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Consent\CookieConsentService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$uuid = static function (): string {
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-cookie-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-cookie-test');

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$service = $container->getByClass(InjectableFactory::class)->create(CookieConsentService::class);
$pdo = $entityManager->getPDO();

$pdo->beginTransaction();
try {
    $alpha = $store->runWith($tenantA, fn (): array => $service->getWorkspace());
    $beta = $store->runWith($tenantB, fn (): array => $service->getWorkspace());
    $assert($alpha['banner']['publicKey'] !== $beta['banner']['publicKey'], 'Tenants must receive different public cookie keys.');
    $assert(count($alpha['banner']['categories']) === 4, 'Default cookie categories were not provisioned.');

    $saved = $store->runWith($tenantA, fn (): array => $service->save((object) [
        'name' => 'Alpha website', 'policyVersion' => '2026.1', 'locale' => 'en-GB', 'integrationMode' => 'existing_banner', 'regionMode' => 'custom',
        'regions' => ['GB'],
        'position' => 'bottom', 'privacyNoticeUrl' => 'https://alpha.example/privacy', 'heading' => 'Alpha privacy choices',
        'message' => 'Choose how Alpha may use optional cookies.', 'primaryColor' => '#087F6D',
        'backgroundColor' => '#FFFFFF', 'textColor' => '#172B26', 'showReject' => true, 'isPublished' => true,
        'categories' => [
            (object) ['key' => 'necessary', 'name' => 'Necessary', 'description' => 'Required.', 'isEssential' => true, 'defaultEnabled' => true],
            (object) ['key' => 'analytics', 'name' => 'Analytics', 'description' => 'Measurement.', 'isEssential' => false, 'defaultEnabled' => false],
        ],
    ]));
    $public = $service->getPublicConfig($saved['banner']['publicKey'], 'GB');
    $assert($public['heading'] === 'Alpha privacy choices', 'Published cookie configuration could not be loaded.');
    $assert($public['integrationMode'] === 'existing_banner', 'Existing website banner mode was not retained.');
    $assert(str_contains($saved['banner']['embedCode'], 'data-nexa-cookie-mode="existing_banner"'), 'Existing-banner embed code was not generated.');
    $assert($public['shouldDisplay'] === true, 'Configured region should display the cookie banner.');
    $assert($service->getPublicConfig($saved['banner']['publicKey'], 'US')['shouldDisplay'] === false, 'Unconfigured region should not display the cookie banner.');
    $receiptInput = (object) [
        'publicKey' => $saved['banner']['publicKey'], 'receiptKey' => $uuid(), 'visitorId' => $uuid(),
        'choice' => 'reject_optional', 'categories' => (object) ['necessary' => true, 'analytics' => false],
        'pageUrl' => 'https://alpha.example/pricing', 'locale' => 'en-GB', 'globalPrivacyControl' => true,
    ];
    $receipt = $service->recordReceipt($receiptInput);
    $service->recordReceipt($receiptInput);
    $assert($receipt['success'] === true, 'Cookie receipt was not recorded.');
    $count = $pdo->prepare('SELECT COUNT(*) FROM nexa_cookie_receipt WHERE tenant_id=? AND service_id=?');
    $count->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $count->fetchColumn() === 1, 'Tenant A cookie receipt was not isolated or idempotent.');
    $count->execute([$tenantB->tenantId, $tenantB->serviceId]);
    $assert((int) $count->fetchColumn() === 0, 'Tenant B can see Tenant A cookie receipt.');
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "Tenant cookie consent tests passed.\n";
