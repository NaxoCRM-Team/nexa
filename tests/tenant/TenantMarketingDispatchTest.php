<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Campaign\CampaignWorkspaceService;
use Espo\Custom\Tools\Consent\ConsentService;
use Espo\Custom\Tools\MarketingEmail\MarketingDeliveryService;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-marketing-dispatch-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-marketing-dispatch-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$services = $container->getByClass(ServiceContainer::class);
$pdo = $entityManager->getPDO();
$transactions = $entityManager->getTransactionManager();
$transactions->start();

try {
    $contact = $store->runWith($tenantA, fn () => $services->get('Contact')->create((object) [
        'firstName' => 'Governed', 'lastName' => 'Recipient',
        'emailAddress' => 'governed-recipient@example.test',
        'addressCountry' => 'Dispatch Test', 'marketingStatus' => 'Marketing',
    ], CreateParams::create()));
    $pdo->prepare("UPDATE contact SET marketing_status='Marketing',do_not_contact=0 WHERE id=? AND tenant_id=? AND service_id=?")
        ->execute([$contact->getId(), $tenantA->tenantId, $tenantA->serviceId]);

    $consent = $store->runWith($tenantA, fn (): array => $factory->create(ConsentService::class)->getWorkspace());
    $purpose = array_values(array_filter($consent['purposes'], static fn (array $item): bool => $item['purposeKey'] === 'marketing_communications'))[0];
    $purposeId = (string) $purpose['id'];
    $store->runWith($tenantA, fn (): array => $factory->create(ConsentService::class)->recordDecision((object) [
        'contactId' => $contact->getId(), 'purposeId' => $purposeId, 'channel' => 'email',
        'status' => 'granted', 'source' => 'manual', 'legalBasis' => 'FreelyGivenConsent',
        'evidenceNote' => 'Governed dispatcher tenant acceptance test.',
    ]));
    $segment = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->save((object) [
        'name' => 'Dispatcher acceptance audience', 'type' => 'dynamic', 'matchMode' => 'all',
        'rules' => [(object) ['field' => 'country', 'operator' => 'equals', 'value' => 'Dispatch Test']],
    ]));
    $campaign = $store->runWith($tenantA, fn (): array => $factory->create(CampaignWorkspaceService::class)->save((object) [
        'name' => 'Dispatcher acceptance campaign', 'type' => 'Email', 'channel' => 'email',
        'purposeId' => $purposeId, 'audienceIds' => [$segment['id']], 'exclusionIds' => [], 'enrollmentMode' => 'snapshot',
    ]));
    $enrollment = $store->runWith($tenantA, fn (): array => $factory->create(CampaignWorkspaceService::class)->enroll((string) $campaign['id']));
    $assert($enrollment['eligibleCount'] === 1, 'The dispatcher fixture did not produce one eligible recipient.');

    $draft = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->save((object) [
        'name' => 'Governed dispatcher email', 'campaignId' => $campaign['id'], 'purposeId' => $purposeId,
        'subject' => 'Governed delivery test', 'fromAddress' => 'news@example.test', 'fromName' => 'Example team',
        'bodyHtml' => '<p>Hello {{contact.firstName}},</p><p>Dispatcher test.</p>',
        'startAt' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]));
    $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'submit']));
    $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'approve']));

    $settings = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDeliveryService::class)->saveProvider((object) [
        'apiBaseUrl' => 'https://postal.example.test', 'serverName' => 'Dispatch test',
        'apiKey' => 'dispatch-acceptance-key', 'monthlySendLimit' => 100,
    ]));
    $settings = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDeliveryService::class)->saveDomain((object) ['domainName' => 'example.test']));
    $domainId = (string) $settings['domains'][0]['id'];
    $pdo->prepare("UPDATE nexa_marketing_sending_domain SET ownership_status='verified',spf_status='verified',dkim_status='verified',dmarc_status='verified',return_path_status='verified',verified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?")
        ->execute([$domainId, $tenantA->tenantId, $tenantA->serviceId]);

    $scheduled = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDispatchService::class)->schedule((string) $draft['id']));
    $assert($scheduled['status'] === 'scheduled' && $scheduled['eligibleCount'] === 1, 'The approved email was not scheduled with its eligible audience.');
    $profile = $pdo->prepare('SELECT lifecycle_status FROM nexa_marketing_email_profile WHERE id=? AND tenant_id=? AND service_id=?');
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'scheduled', 'The governed profile did not enter scheduled state.');
    $native = $pdo->prepare('SELECT status FROM mass_email WHERE id=? AND tenant_id=? AND service_id=?');
    $native->execute([$draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($native->fetchColumn() === 'Nexa Scheduled', 'The native MassEmail was not reserved away from the SMTP processor.');

    // Eligibility is deliberately changed after scheduling. The worker must
    // suppress the recipient at send time and must not contact the provider.
    $pdo->prepare('UPDATE contact SET do_not_contact=1 WHERE id=? AND tenant_id=? AND service_id=?')
        ->execute([$contact->getId(), $tenantA->tenantId, $tenantA->serviceId]);
    $pdo->prepare('UPDATE mass_email SET start_at=? WHERE id=? AND tenant_id=? AND service_id=?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 60), $draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
    $queue = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND deleted=0');
    $queue->execute([$tenantA->tenantId, $tenantA->serviceId, $draft['massEmailId']]);
    $assert((int) $queue->fetchColumn() === 0, 'A recipient that became do-not-contact was added to the delivery queue.');
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'completed', 'A fully suppressed scheduled email did not finish without delivery.');

    $isolated = false;
    try {
        $store->runWith($tenantB, fn (): array => $factory->create(MarketingDispatchService::class)->schedule((string) $draft['id']));
    } catch (NotFound) {
        $isolated = true;
    }
    $assert($isolated, 'Tenant B could schedule Tenant A marketing email.');
    echo "Tenant marketing dispatch tests passed.\n";
} finally {
    $transactions->rollback();
}
