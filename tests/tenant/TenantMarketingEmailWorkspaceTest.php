<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Campaign\CampaignWorkspaceService;
use Espo\Custom\Tools\Consent\ConsentService;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-marketing-email-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-marketing-email-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$pdo = $entityManager->getPDO();
$transactions = $entityManager->getTransactionManager();
$transactions->start();

try {
    $consent = $store->runWith($tenantA, fn (): array => $factory->create(ConsentService::class)->getWorkspace());
    $purposes = array_values(array_filter($consent['purposes'], static fn (array $item): bool => $item['purposeKey'] === 'marketing_communications'));
    $assert(count($purposes) === 1, 'The marketing communications purpose is missing.');
    $purposeId = (string) $purposes[0]['id'];

    $segment = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->save((object) [
        'name' => 'Marketing email acceptance audience',
        'type' => 'static',
        'matchMode' => 'all',
        'rules' => [],
    ]));
    $campaign = $store->runWith($tenantA, fn (): array => $factory->create(CampaignWorkspaceService::class)->save((object) [
        'name' => 'Marketing email acceptance campaign',
        'type' => 'Email',
        'channel' => 'email',
        'purposeId' => $purposeId,
        'audienceIds' => [$segment['id']],
        'exclusionIds' => [],
        'enrollmentMode' => 'snapshot',
    ]));

    $draft = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->save((object) [
        'name' => 'October customer update',
        'campaignId' => $campaign['id'],
        'purposeId' => $purposeId,
        'subject' => 'Your October update',
        'preheader' => 'The latest customer news.',
        'fromAddress' => 'news@example.test',
        'fromName' => 'Example team',
        'replyToAddress' => 'reply@example.test',
        'bodyHtml' => '<p>Hello {{contact.firstName}},</p><p>Here is your update.</p>',
    ]));

    $profile = $pdo->prepare('SELECT tenant_id,service_id,mass_email_id,lifecycle_status,version_number FROM nexa_marketing_email_profile WHERE id=?');
    $profile->execute([$draft['id']]);
    $row = $profile->fetch(PDO::FETCH_ASSOC);
    $assert($row && $row['tenant_id'] === $tenantA->tenantId && $row['service_id'] === $tenantA->serviceId, 'Marketing email profile lacks the current tenant and service.');
    $assert($row['lifecycle_status'] === 'draft' && (int) $row['version_number'] === 1, 'Marketing email did not start as version one draft.');

    $native = $pdo->prepare('SELECT m.tenant_id,m.service_id,m.email_template_id,t.tenant_id template_tenant,t.service_id template_service FROM mass_email m INNER JOIN email_template t ON t.id=m.email_template_id WHERE m.id=?');
    $native->execute([$row['mass_email_id']]);
    $nativeRow = $native->fetch(PDO::FETCH_ASSOC);
    $assert($nativeRow && $nativeRow['tenant_id'] === $tenantA->tenantId && $nativeRow['service_id'] === $tenantA->serviceId, 'Native MassEmail is not tenant and service scoped.');
    $assert($nativeRow['template_tenant'] === $tenantA->tenantId && $nativeRow['template_service'] === $tenantA->serviceId, 'Native EmailTemplate is not tenant and service scoped.');

    $workspaceA = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->getWorkspace());
    $workspaceB = $store->runWith($tenantB, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->getWorkspace());
    $assert(count(array_filter($workspaceA['emails'], static fn (array $item): bool => $item['id'] === $draft['id'])) === 1, 'Tenant A cannot see its marketing email.');
    $assert(count(array_filter($workspaceB['emails'], static fn (array $item): bool => $item['id'] === $draft['id'])) === 0, 'Tenant B can see Tenant A marketing email.');
    $assert($workspaceA['deliveryEnabled'] === false, 'Delivery unlocked before provider and domain governance.');

    $submitted = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'submit']));
    $approved = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'approve']));
    $assert($submitted['status'] === 'review' && $approved['status'] === 'approved', 'Marketing email review and approval transitions failed.');

    $event = $pdo->prepare("SELECT COUNT(*) FROM nexa_marketing_email_event WHERE tenant_id=? AND service_id=? AND profile_id=? AND event_type IN ('created','review','approved')");
    $event->execute([$tenantA->tenantId, $tenantA->serviceId, $draft['id']]);
    $assert((int) $event->fetchColumn() === 3, 'Marketing email immutable lifecycle history is incomplete.');
    $version = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_email_version WHERE tenant_id=? AND service_id=? AND profile_id=? AND version_number=1');
    $version->execute([$tenantA->tenantId, $tenantA->serviceId, $draft['id']]);
    $assert((int) $version->fetchColumn() === 1, 'Marketing email content version was not recorded once.');

    echo "Tenant marketing email workspace tests passed.\n";
} finally {
    $transactions->rollback();
}
