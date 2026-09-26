<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Consent\ConsentService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$id = static fn (string $prefix): string => substr($prefix . bin2hex(random_bytes(8)), 0, 17);
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-consent-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-consent-test');

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$service = $container->getByClass(InjectableFactory::class)->create(ConsentService::class);
$pdo = $entityManager->getPDO();
$contactA = $id('nxconsenta');
$contactB = $id('nxconsentb');
$suffix = bin2hex(random_bytes(4));
$purposeId = '';

$pdo->beginTransaction();
try {
    $store->runWith($tenantA, function () use ($entityManager, $contactA, $suffix): void {
        $entityManager->createEntity('Contact', [
            'id' => $contactA, 'firstName' => 'Consent', 'lastName' => "Alpha {$suffix}",
            'emailAddress' => "consent-alpha-{$suffix}@example.test", 'marketingStatus' => 'Non-Marketing',
        ]);
    });
    $store->runWith($tenantB, function () use ($entityManager, $contactB, $suffix): void {
        $entityManager->createEntity('Contact', [
            'id' => $contactB, 'firstName' => 'Consent', 'lastName' => "Beta {$suffix}",
            'emailAddress' => "consent-beta-{$suffix}@example.test", 'marketingStatus' => 'Non-Marketing',
        ]);
    });

    $alpha = $store->runWith($tenantA, function () use ($service, $contactA, $suffix, &$purposeId): array {
        $workspace = $service->getWorkspace();
        if (count($workspace['purposes']) < 3) throw new RuntimeException('Tenant A default consent purposes were not provisioned.');
        $marketingPurpose = array_values(array_filter(
            $workspace['purposes'],
            static fn (array $purpose): bool => $purpose['purposeKey'] === 'marketing_communications',
        ))[0] ?? throw new RuntimeException('Marketing communications purpose is missing.');
        $saved = $service->savePurpose((object) [
            'name' => "Product research {$suffix}", 'channels' => ['email'],
            'defaultLegalBasis' => 'FreelyGivenConsent', 'policyVersion' => '2026.1',
            'privacyNoticeUrl' => 'https://example.test/privacy',
        ]);
        $purposeId = (string) $saved['purpose']['id'];
        return $service->recordDecision((object) [
            'contactId' => $contactA, 'purposeId' => $marketingPurpose['id'], 'channel' => 'email',
            'status' => 'granted', 'legalBasis' => 'FreelyGivenConsent', 'source' => 'manual',
            'evidenceNote' => 'Consent confirmed during a recorded customer conversation.',
        ]);
    });

    $assert(count($alpha['states']) === 1, 'Tenant A current consent state was not created.');
    $assert($alpha['states'][0]['status'] === 'granted', 'Tenant A consent status is incorrect.');
    $nativeStatus = $pdo->prepare('SELECT marketing_status FROM contact WHERE tenant_id=? AND service_id=? AND id=?');
    $nativeStatus->execute([$tenantA->tenantId, $tenantA->serviceId, $contactA]);
    $nativeMarketingStatus = $nativeStatus->fetchColumn();
    $assert(
        $nativeMarketingStatus === 'Marketing',
        'Granted consent did not update native Contact marketing status; received ' . var_export($nativeMarketingStatus, true) . '.',
    );
    $assert($alpha['contact']['marketingStatus'] === 'Marketing', 'Consent API returned a stale Contact marketing status.');

    $eventQuery = $pdo->prepare("SELECT id,purpose_id FROM nexa_consent_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND channel='email' ORDER BY occurred_at DESC LIMIT 1");
    $eventQuery->execute([$tenantA->tenantId, $tenantA->serviceId, $contactA]);
    $originalEvent = $eventQuery->fetch(PDO::FETCH_ASSOC);
    $assert((bool) $originalEvent, 'Original consent event was not retained.');
    $store->runWith($tenantA, fn (): array => $service->recordDecision((object) [
        'contactId' => $contactA, 'purposeId' => $originalEvent['purpose_id'], 'channel' => 'email',
        'status' => 'denied', 'legalBasis' => 'FreelyGivenConsent', 'source' => 'manual',
        'evidenceNote' => 'Correction: the Contact did not grant marketing consent.',
        'correctsEventId' => $originalEvent['id'],
    ]));
    $correctionQuery = $pdo->prepare('SELECT id FROM nexa_consent_event WHERE tenant_id=? AND service_id=? AND supersedes_event_id=? LIMIT 1');
    $correctionQuery->execute([$tenantA->tenantId, $tenantA->serviceId, $originalEvent['id']]);
    $correctionId = (string) $correctionQuery->fetchColumn();
    $assert($correctionId !== '', 'Consent correction was not appended to the audit history.');
    $statusQuery = $pdo->prepare('SELECT marketing_status FROM contact WHERE tenant_id=? AND service_id=? AND id=?');
    $statusQuery->execute([$tenantA->tenantId, $tenantA->serviceId, $contactA]);
    $assert($statusQuery->fetchColumn() === 'Unsubscribed', 'Denied correction did not activate native email suppression.');
    $store->runWith($tenantA, fn (): array => $service->voidDecision($correctionId, (object) [
        'reason' => 'Correction was entered against the wrong evidence record.',
    ]));
    $statusQuery->execute([$tenantA->tenantId, $tenantA->serviceId, $contactA]);
    $assert($statusQuery->fetchColumn() === 'Marketing', 'Voiding the correction did not restore the prior granted state.');
    $auditQuery = $pdo->prepare('SELECT superseded_by_event_id FROM nexa_consent_event WHERE tenant_id=? AND service_id=? AND id=?');
    $auditQuery->execute([$tenantA->tenantId, $tenantA->serviceId, $originalEvent['id']]);
    $assert($auditQuery->fetchColumn() === null, 'Voiding a correction did not restore the original audit version.');

    $beta = $store->runWith($tenantB, fn (): array => $service->getWorkspace());
    $assert(!in_array($purposeId, array_column($beta['purposes'], 'id'), true), 'Tenant B can see Tenant A custom consent purpose.');
    $crossTenantBlocked = false;
    try {
        $store->runWith($tenantB, fn (): array => $service->getContactConsent($contactA));
    } catch (Throwable) {
        $crossTenantBlocked = true;
    }
    $assert($crossTenantBlocked, 'Tenant B can read Tenant A consent record.');

    $statement = $pdo->prepare(
        'SELECT tenant_id, service_id, COUNT(*) AS quantity FROM nexa_consent_event ' .
        'WHERE contact_id IN (?, ?) GROUP BY tenant_id, service_id'
    );
    $statement->execute([$contactA, $contactB]);
    $events = $statement->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($events) === 1, 'Consent events escaped their tenant and service boundary.');
    $assert($events[0]['tenant_id'] === $tenantA->tenantId, 'Consent event was assigned to the wrong tenant.');
    $assert($events[0]['service_id'] === $tenantA->serviceId, 'Consent event was assigned to the wrong service.');

    echo "Tenant consent governance isolation tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
