<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Custom\Tools\Event\PublicEventCollectorService;
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);
$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$factory = $container->getByClass(InjectableFactory::class);
$transactions = $entityManager->getTransactionManager();
$pdo = $entityManager->getPDO();
$tenantA = '30000000-0000-4000-8000-000000000001';
$tenantB = '30000000-0000-4000-8000-000000000002';
$serviceQuery = $pdo->prepare(
    'SELECT a.service_id FROM nexa_tenant_service a INNER JOIN nexa_tenant_service b ON b.service_id=a.service_id ' .
    'AND b.tenant_id=? WHERE a.tenant_id=? ORDER BY a.service_id LIMIT 1'
);
$serviceQuery->execute([$tenantB, $tenantA]);
$service = (string) $serviceQuery->fetchColumn();
$assert($service !== '', 'The two-tenant fixture does not provide a shared service.');
$sourceA = '9b000000-0000-4000-8000-000000000001';
$sourceB = '9b000000-0000-4000-8000-000000000002';
$keyA = str_repeat('a', 48);
$keyB = str_repeat('b', 48);
$_SERVER['REMOTE_ADDR'] = '127.0.0.29';
$_SERVER['HTTP_USER_AGENT'] = 'Nexa collector isolation test';

$transactions->start();
try {
    $insert = $pdo->prepare('INSERT INTO nexa_tracking_source (id,tenant_id,service_id,name,public_key,status,integration_mode,allowed_origins_json) VALUES (?,?,?,?,?,?,?,?)');
    $insert->execute([$sourceA, $tenantA, $service, 'Collector Alpha', $keyA, 'active', 'external', '["https://alpha.example"]']);
    $insert->execute([$sourceB, $tenantB, $service, 'Collector Beta', $keyB, 'active', 'external', '["https://beta.example"]']);
    $collector = $factory->create(PublicEventCollectorService::class);
    $payload = (object) [
        'eventType' => 'page.viewed', 'eventVersion' => 1, 'idempotencyKey' => 'public-alpha-page-1',
        'visitorKey' => 'alpha-browser-visitor', 'sessionKey' => 'alpha-browser-session',
        'pageUrl' => 'https://alpha.example/pricing', 'properties' => (object) ['title' => 'Pricing'],
        'consent' => (object) ['analytics' => 'granted'],
        'consentEvidence' => (object) ['provider' => 'Tenant CMP', 'policyVersion' => '2026.10'],
    ];
    $first = $collector->collect($keyA, 'https://alpha.example', $payload, false);
    $retry = $collector->collect($keyA, 'https://alpha.example', $payload, false);
    $assert(!$first['duplicate'] && $retry['duplicate'], 'Public collector idempotency failed.');
    $stored = $pdo->prepare('SELECT tenant_id,service_id,contact_id,account_id,source FROM nexa_behavior_event WHERE id=?');
    $stored->execute([$first['id']]);
    $row = $stored->fetch(PDO::FETCH_ASSOC);
    $assert($row && $row['tenant_id'] === $tenantA && $row['service_id'] === $service, 'Public event was stored outside its source scope.');
    $assert($row['contact_id'] === null && $row['account_id'] === null, 'Anonymous public event acquired a CRM identity.');
    $assert(str_starts_with((string) $row['source'], 'web.'), 'Public event source was not server assigned.');
    $originDenied = false;
    try { $collector->collect($keyA, 'https://beta.example', clone $payload, false); } catch (Forbidden) { $originDenied = true; }
    $assert($originDenied, 'An unapproved origin could submit to the tracking source.');
    $identityDenied = false;
    try {
        $spoofed = clone $payload; $spoofed->idempotencyKey = 'public-spoof-attempt'; $spoofed->contactId = 'spoofed-contact';
        $collector->collect($keyA, 'https://alpha.example', $spoofed, false);
    } catch (Forbidden) { $identityDenied = true; }
    $assert($identityDenied, 'A public caller could supply a trusted CRM identity.');
    $gpcDenied = false;
    try {
        $advertising = clone $payload; $advertising->eventType = 'custom.ad-impression'; $advertising->consentCategory = 'advertising';
        $advertising->idempotencyKey = 'public-gpc-attempt'; $advertising->consent = (object) ['advertising' => 'granted'];
        $collector->collect($keyA, 'https://alpha.example', $advertising, true);
    } catch (Forbidden) { $gpcDenied = true; }
    $assert($gpcDenied, 'Global Privacy Control did not suppress an advertising event.');
    $otherTenant = $pdo->prepare('SELECT COUNT(*) FROM nexa_behavior_event WHERE id=? AND tenant_id=?');
    $otherTenant->execute([$first['id'], $tenantB]);
    $assert((int) $otherTenant->fetchColumn() === 0, 'The collected event leaked into another tenant.');
    echo "Tenant public event collector tests passed.\n";
} finally {
    $transactions->rollback();
}
