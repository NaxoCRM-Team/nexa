<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Event\BehaviorEventService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$contextStore = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$records = $container->getByClass(ServiceContainer::class);
$transactions = $entityManager->getTransactionManager();
$pdo = $entityManager->getPDO();
$tenantA = new TenantContext(
    '30000000-0000-4000-8000-000000000001',
    'isolation-alpha',
    'behavior-identity-backfill-test',
);
$tenantB = new TenantContext(
    '30000000-0000-4000-8000-000000000002',
    'isolation-beta',
    'behavior-identity-backfill-test',
);
$visitorKey = 'shared-browser-key-that-must-remain-tenant-scoped';

$ingest = static function (
    TenantContextStore $store,
    InjectableFactory $factory,
    TenantContext $context,
    array $payload,
): array {
    return $store->runWith(
        $context,
        static fn (): array => $factory->create(BehaviorEventService::class)->ingest((object) $payload),
    );
};

$transactions->start();
try {
    $account = $contextStore->runWith(
        $tenantA,
        static fn () => $records->get('Account')->create(
            (object) ['name' => 'Behavior Timeline Account'],
            CreateParams::create(),
        ),
    );
    $contact = $contextStore->runWith(
        $tenantA,
        static fn () => $records->get('Contact')->create(
            (object) [
                'firstName' => 'Timeline',
                'lastName' => 'Contact',
                'accountId' => $account->getId(),
            ],
            CreateParams::create(),
        ),
    );

    $anonymousPage = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'page.viewed',
        'source' => 'identity-backfill-test',
        'idempotencyKey' => 'anonymous-page',
        'visitorKey' => $visitorKey,
        'occurredAt' => '2026-09-01T10:00:00Z',
        'pageUrl' => 'https://example.test/pricing',
        'properties' => (object) ['title' => 'Pricing'],
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $anonymousLink = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'link.clicked',
        'source' => 'identity-backfill-test',
        'idempotencyKey' => 'anonymous-link',
        'visitorKey' => $visitorKey,
        'occurredAt' => '2026-09-01T10:05:00Z',
        'pageUrl' => 'https://example.test/pricing',
        'properties' => (object) ['title' => 'Book a demo'],
        'consent' => (object) ['analytics' => 'granted'],
    ]);

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_behavior_event WHERE tenant_id = ? AND service_id = ? ' .
        'AND id IN (?, ?) AND contact_id IS NULL'
    );
    $statement->execute([
        $tenantA->tenantId,
        $tenantA->serviceId,
        $anonymousPage['id'],
        $anonymousLink['id'],
    ]);
    $assert((int) $statement->fetchColumn() === 2, 'Anonymous events were identified before verification.');

    $verified = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'form.submitted',
        'source' => 'identity-backfill-test',
        'idempotencyKey' => 'verified-form',
        'visitorKey' => $visitorKey,
        'contactId' => $contact->getId(),
        'identityEvidence' => (object) [
            'type' => 'form_submission',
            'verified' => true,
            'reference' => 'verified-form-submission-1',
        ],
        'occurredAt' => '2026-09-01T10:10:00Z',
        'pageUrl' => 'https://example.test/demo',
        'properties' => (object) ['formName' => 'Request a demo'],
    ]);
    $retry = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'form.submitted',
        'source' => 'identity-backfill-test',
        'idempotencyKey' => 'verified-form',
        'visitorKey' => $visitorKey,
        'contactId' => $contact->getId(),
        'identityEvidence' => (object) [
            'type' => 'form_submission',
            'verified' => true,
            'reference' => 'verified-form-submission-1',
        ],
        'occurredAt' => '2026-09-01T10:10:00Z',
        'pageUrl' => 'https://example.test/demo',
        'properties' => (object) ['formName' => 'Request a demo'],
    ]);
    $assert($retry['duplicate'] === true && $retry['id'] === $verified['id'], 'Verified retry was not idempotent.');

    $laterPage = $ingest($contextStore, $factory, $tenantA, [
        'eventType' => 'page.viewed',
        'source' => 'identity-backfill-test',
        'idempotencyKey' => 'identified-page',
        'visitorKey' => $visitorKey,
        'occurredAt' => '2026-09-01T10:20:00Z',
        'pageUrl' => 'https://example.test/thank-you',
        'properties' => (object) ['title' => 'Thank you'],
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $assert($laterPage['identified'] === true, 'A later event did not inherit the verified visitor identity.');

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM nexa_behavior_event WHERE tenant_id = ? AND service_id = ? ' .
        'AND visitor_identity_id = (SELECT visitor_identity_id FROM nexa_behavior_event WHERE id = ?) ' .
        'AND contact_id = ? AND account_id = ?'
    );
    $statement->execute([
        $tenantA->tenantId,
        $tenantA->serviceId,
        $verified['id'],
        $contact->getId(),
        $account->getId(),
    ]);
    $assert((int) $statement->fetchColumn() === 4, 'Historical or later visitor events were not linked to the customer.');

    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id = ? AND service_id = ? " .
        "AND contact_id = ? AND source_entity_type = 'BehaviorEvent'"
    );
    $statement->execute([$tenantA->tenantId, $tenantA->serviceId, $contact->getId()]);
    $assert((int) $statement->fetchColumn() === 4, 'The canonical customer timeline is incomplete or duplicated.');

    $statement = $pdo->prepare(
        'SELECT last_website_visit_at FROM contact WHERE tenant_id = ? AND service_id = ? AND id = ?'
    );
    $statement->execute([$tenantA->tenantId, $tenantA->serviceId, $contact->getId()]);
    $assert(
        str_starts_with((string) $statement->fetchColumn(), '2026-09-01 10:20:00'),
        'The Contact last website visit was not reconciled from identified behavior.',
    );

    $otherTenant = $ingest($contextStore, $factory, $tenantB, [
        'eventType' => 'page.viewed',
        'source' => 'identity-backfill-test',
        'idempotencyKey' => 'tenant-b-page',
        'visitorKey' => $visitorKey,
        'occurredAt' => '2026-09-01T10:30:00Z',
        'pageUrl' => 'https://other.example.test/',
        'consent' => (object) ['analytics' => 'granted'],
    ]);
    $statement = $pdo->prepare(
        'SELECT contact_id FROM nexa_behavior_event WHERE tenant_id = ? AND service_id = ? AND id = ?'
    );
    $statement->execute([$tenantB->tenantId, $tenantB->serviceId, $otherTenant['id']]);
    $assert($statement->fetchColumn() === null, 'Verified identity leaked into another tenant.');

    echo "Tenant behavior identity backfill tests passed.\n";
} finally {
    $transactions->rollback();
}
