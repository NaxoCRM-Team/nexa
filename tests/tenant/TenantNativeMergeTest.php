<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Action\ActionFactory;
use Espo\Core\Action\Data;
use Espo\Core\Action\Params;
use Espo\Core\Application;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$recordId = static fn (string $prefix): string => substr($prefix . bin2hex(random_bytes(8)), 0, 17);
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
};
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'native-merge-runtime-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'native-merge-runtime-test');
$ids = [
    'contactTarget' => $recordId('nxmct'), 'contactSource' => $recordId('nxmcs'), 'contactForeign' => $recordId('nxmcf'),
    'accountTarget' => $recordId('nxmat'), 'accountSource' => $recordId('nxmas'), 'accountContact' => $recordId('nxmac'),
    'note' => $recordId('nxmno'), 'fieldMove' => $uuid(), 'fieldConflict' => $uuid(),
    'valueMove' => $uuid(), 'valueTarget' => $uuid(), 'valueSource' => $uuid(),
];

$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$contextStore = $container->getByClass(TenantContextStore::class);
$injectableFactory = $container->getByClass(InjectableFactory::class);
$actionFactory = $injectableFactory->create(ActionFactory::class);
$pdo = $entityManager->getPDO();
$pdo->beginTransaction();

try {
    $contextStore->runWith($tenantA, function () use ($entityManager, $pdo, $tenantA, $ids): void {
        $entityManager->createEntity('Contact', [
            'id' => $ids['contactTarget'], 'firstName' => 'Merge', 'lastName' => 'Survivor',
            'emailAddress' => 'merge-survivor@example.test',
        ]);
        $entityManager->createEntity('Contact', [
            'id' => $ids['contactSource'], 'firstName' => 'Merge', 'lastName' => 'Source',
            'emailAddress' => 'merge-source@example.test',
        ]);
        $entityManager->createEntity('Note', [
            'id' => $ids['note'], 'type' => 'Post', 'post' => 'Source merge note',
            'parentType' => 'Contact', 'parentId' => $ids['contactSource'],
        ]);
        $entityManager->createEntity('Account', ['id' => $ids['accountTarget'], 'name' => 'Account Survivor']);
        $entityManager->createEntity('Account', ['id' => $ids['accountSource'], 'name' => 'Account Source']);
        $entityManager->createEntity('Contact', [
            'id' => $ids['accountContact'], 'firstName' => 'Account', 'lastName' => 'Member',
            'accountId' => $ids['accountSource'],
        ]);

        $definition = $pdo->prepare(
            'INSERT INTO nexa_custom_field_definition ' .
            '(id, tenant_id, service_id, entity_type, field_key, label, data_type, created_by_id) ' .
            "VALUES (?, ?, ?, 'Contact', ?, ?, 'text', 'system')"
        );
        $definition->execute([$ids['fieldMove'], $tenantA->tenantId, $tenantA->serviceId, 'merge_move_' . substr($ids['fieldMove'], 0, 8), 'Merge Move']);
        $definition->execute([$ids['fieldConflict'], $tenantA->tenantId, $tenantA->serviceId, 'merge_conflict_' . substr($ids['fieldConflict'], 0, 8), 'Merge Conflict']);
        $value = $pdo->prepare(
            'INSERT INTO nexa_custom_field_value ' .
            '(id, tenant_id, service_id, field_definition_id, entity_type, entity_id, value_text) ' .
            "VALUES (?, ?, ?, ?, 'Contact', ?, ?)"
        );
        $value->execute([$ids['valueMove'], $tenantA->tenantId, $tenantA->serviceId, $ids['fieldMove'], $ids['contactSource'], 'move-me']);
        $value->execute([$ids['valueTarget'], $tenantA->tenantId, $tenantA->serviceId, $ids['fieldConflict'], $ids['contactTarget'], 'survivor-wins']);
        $value->execute([$ids['valueSource'], $tenantA->tenantId, $tenantA->serviceId, $ids['fieldConflict'], $ids['contactSource'], 'discard-me']);
    });

    $contextStore->runWith($tenantB, function () use ($entityManager, $ids): void {
        $entityManager->createEntity('Contact', [
            'id' => $ids['contactForeign'], 'firstName' => 'Foreign', 'lastName' => 'Tenant',
            'emailAddress' => 'merge-foreign@example.test',
        ]);
    });

    $contextStore->runWith($tenantA, function () use ($actionFactory, $ids): void {
        $actionFactory->create('merge', 'Contact')->process(
            new Params('Contact', $ids['contactTarget']),
            Data::fromRaw((object) ['sourceIdList' => [$ids['contactSource']], 'attributes' => (object) []]),
        );
        $actionFactory->create('merge', 'Account')->process(
            new Params('Account', $ids['accountTarget']),
            Data::fromRaw((object) ['sourceIdList' => [$ids['accountSource']], 'attributes' => (object) []]),
        );
    });

    $sourceDeleted = $pdo->prepare('SELECT deleted FROM contact WHERE tenant_id = ? AND service_id = ? AND id = ?');
    $sourceDeleted->execute([$tenantA->tenantId, $tenantA->serviceId, $ids['contactSource']]);
    $assert((int) $sourceDeleted->fetchColumn() === 1, 'The losing Contact was not soft-deleted.');
    $sourceDeleted = $pdo->prepare('SELECT deleted FROM account WHERE tenant_id = ? AND service_id = ? AND id = ?');
    $sourceDeleted->execute([$tenantA->tenantId, $tenantA->serviceId, $ids['accountSource']]);
    $assert((int) $sourceDeleted->fetchColumn() === 1, 'The losing Account was not soft-deleted.');

    $note = $pdo->prepare('SELECT parent_id FROM note WHERE tenant_id = ? AND service_id = ? AND id = ?');
    $note->execute([$tenantA->tenantId, $tenantA->serviceId, $ids['note']]);
    $assert($note->fetchColumn() === $ids['contactTarget'], 'The native merger did not move the source note.');

    $values = $pdo->prepare(
        'SELECT field_definition_id, value_text FROM nexa_custom_field_value ' .
        "WHERE tenant_id = ? AND service_id = ? AND entity_type = 'Contact' AND entity_id = ?"
    );
    $values->execute([$tenantA->tenantId, $tenantA->serviceId, $ids['contactTarget']]);
    $values = array_column($values->fetchAll(PDO::FETCH_ASSOC), 'value_text', 'field_definition_id');
    $assert(($values[$ids['fieldMove']] ?? null) === 'move-me', 'A source-only custom property was not preserved.');
    $assert(($values[$ids['fieldConflict']] ?? null) === 'survivor-wins', 'The survivor did not win a custom-property conflict.');

    $identity = $pdo->prepare('SELECT COUNT(*) FROM nexa_identity_link WHERE tenant_id = ? AND contact_id = ?');
    $identity->execute([$tenantA->tenantId, $ids['contactTarget']]);
    $assert((int) $identity->fetchColumn() >= 2, 'Contact identities were not consolidated.');
    $timeline = $pdo->prepare('SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id = ? AND contact_id = ?');
    $timeline->execute([$tenantA->tenantId, $ids['contactTarget']]);
    $assert((int) $timeline->fetchColumn() >= 2, 'Contact timeline history was not consolidated.');
    $accountTimeline = $pdo->prepare('SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id = ? AND account_id = ?');
    $accountTimeline->execute([$tenantA->tenantId, $ids['accountTarget']]);
    $assert((int) $accountTimeline->fetchColumn() >= 2, 'Account timeline history was not consolidated.');

    $audit = $pdo->prepare("SELECT COUNT(*) FROM nexa_audit_event WHERE tenant_id = ? AND action = 'crm.record.merged' AND subject_id IN (?, ?)");
    $audit->execute([$tenantA->tenantId, $ids['contactTarget'], $ids['accountTarget']]);
    $assert((int) $audit->fetchColumn() === 2, 'Contact and Account merge audit events were not recorded.');

    $blocked = false;
    try {
        $contextStore->runWith($tenantA, function () use ($actionFactory, $ids): void {
            $actionFactory->create('merge', 'Contact')->process(
                new Params('Contact', $ids['contactTarget']),
                Data::fromRaw((object) ['sourceIdList' => [$ids['contactForeign']], 'attributes' => (object) []]),
            );
        });
    } catch (NotFound) {
        $blocked = true;
    }
    $assert($blocked, 'A Contact from another tenant could be merged.');
    $foreign = $pdo->prepare('SELECT deleted FROM contact WHERE tenant_id = ? AND service_id = ? AND id = ?');
    $foreign->execute([$tenantB->tenantId, $tenantB->serviceId, $ids['contactForeign']]);
    $assert((int) $foreign->fetchColumn() === 0, 'The rejected cross-tenant merge changed the foreign Contact.');

    echo "Tenant-safe native Contact and Account merge tests passed.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
