<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/espocrm/vendor/autoload.php';

use Espo\Core\Tenant\EntityOwnershipRegistry;
use Espo\Core\Tenant\Exception\MissingTenantContext;
use Espo\Core\Tenant\Exception\TenantScopeViolation;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\TenantQueryProcessor;
use Espo\Core\Tenant\TenantResourceKey;
use Espo\Core\Tenant\TenantSqlExecutor;
use Espo\ORM\Executor\SqlExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\Delete;
use Espo\ORM\Query\Insert;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\Update;

final class TestMetadataProvider implements MetadataDataProvider
{
    public function get(): array
    {
        return [
            'Account' => [
                'attributes' => [],
                'relations' => [
                    'contacts' => ['type' => 'manyMany', 'entity' => 'Contact'],
                ],
            ],
            'Contact' => ['attributes' => [], 'relations' => []],
            'DashboardTemplate' => ['attributes' => [], 'relations' => []],
            'Export' => ['attributes' => [], 'relations' => []],
            'Job' => ['attributes' => [], 'relations' => []],
            'SystemData' => ['attributes' => [], 'relations' => []],
        ];
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException(string $class, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        expect($e instanceof $class, "Expected {$class}, got " . $e::class);
        return;
    }

    throw new RuntimeException("Expected {$class} to be thrown.");
}

$tenantA = new TenantContext(
    '30000000-0000-4000-8000-000000000001',
    'isolation-alpha',
    'test',
    'Isolation Alpha',
);
$tenantB = new TenantContext(
    '30000000-0000-4000-8000-000000000002',
    'isolation-beta',
    'test',
    'Isolation Beta',
);
expect($tenantA->displayName === 'Isolation Alpha', 'Tenant A display name is missing.');
expect($tenantB->displayName === 'Isolation Beta', 'Tenant B display name is missing.');
expect($tenantA->serviceId === TenantContext::CRM_SERVICE_ID, 'Tenant A service is missing.');
expect($tenantB->serviceId === TenantContext::CRM_SERVICE_ID, 'Tenant B service is missing.');
$store = new TenantContextStore();
$processor = new TenantQueryProcessor(
    $store,
    new EntityOwnershipRegistry(),
    new Metadata(new TestMetadataProvider()),
);
$resourceKey = new TenantResourceKey($store);
$rawSqlGuard = new TenantSqlExecutor(
    new class implements SqlExecutor {
        public function execute(string $sql, bool $rerunIfDeadlock = false): PDOStatement
        {
            throw new RuntimeException('Inner SQL executor should not be reached by this test.');
        }
    },
    $store,
);

expectException(TenantScopeViolation::class, fn () => $store->runWith(
    $tenantA,
    fn () => $rawSqlGuard->execute('SELECT * FROM account')
));

expectException(MissingTenantContext::class, fn () => $processor->process(
    Select::fromRaw(['from' => 'Account'])
));

$aRaw = $store->runWith($tenantA, fn () => $processor->process(
    Select::fromRaw(['from' => 'Account', 'whereClause' => ['id' => 'same-local-record']])
)->getRaw());
$bRaw = $store->runWith($tenantB, fn () => $processor->process(
    Select::fromRaw(['from' => 'Account', 'whereClause' => ['id' => 'same-local-record']])
)->getRaw());
expect($aRaw['whereClause'][0]['tenantId'] === $tenantA->tenantId, 'Tenant A read scope is missing.');
expect($aRaw['whereClause'][0]['serviceId'] === $tenantA->serviceId, 'Tenant A service scope is missing.');
expect($bRaw['whereClause'][0]['tenantId'] === $tenantB->tenantId, 'Tenant B read scope is missing.');
expect($bRaw['whereClause'][0]['serviceId'] === $tenantB->serviceId, 'Tenant B service scope is missing.');
expect($aRaw !== $bRaw, 'Synthetic tenant scopes must differ.');

$insert = $store->runWith($tenantA, fn () => $processor->process(Insert::fromRaw([
    'into' => 'Account',
    'columns' => ['id', 'name'],
    'values' => ['id' => 'account-a', 'name' => 'Shared Name'],
]))->getRaw());
expect($insert['values']['tenantId'] === $tenantA->tenantId, 'Insert did not inject tenant ownership.');
expect($insert['values']['serviceId'] === $tenantA->serviceId, 'Insert did not inject service ownership.');
expect(in_array('tenantId', $insert['columns'], true), 'Insert tenant column is missing.');
expect(in_array('serviceId', $insert['columns'], true), 'Insert service column is missing.');

expectException(TenantScopeViolation::class, fn () => $store->runWith($tenantA, fn () =>
    $processor->process(Insert::fromRaw([
        'into' => 'Account',
        'columns' => ['id', 'tenantId'],
        'values' => ['id' => 'bad', 'tenantId' => $tenantB->tenantId],
    ]))
));

expectException(TenantScopeViolation::class, fn () => $store->runWith($tenantA, fn () =>
    $processor->process(Insert::fromRaw([
        'into' => 'Account',
        'columns' => ['id', 'serviceId'],
        'values' => ['id' => 'bad-service', 'serviceId' => $tenantB->serviceId],
    ]))
));

$insertSelect = $store->runWith($tenantA, fn () => $processor->process(Insert::fromRaw([
    'into' => 'Account',
    'columns' => ['id', 'name'],
    'valuesQuery' => Select::fromRaw([
        'from' => 'Contact',
        'select' => ['id', 'lastName'],
    ]),
]))->getRaw());
$insertSelectRaw = $insertSelect['valuesQuery']->getRaw();
expect($insertSelectRaw['whereClause']['tenantId'] === $tenantA->tenantId, 'INSERT SELECT tenant scope is missing.');
expect($insertSelectRaw['whereClause']['serviceId'] === $tenantA->serviceId, 'INSERT SELECT service scope is missing.');
expect(in_array(["VALUE:{$tenantA->tenantId}", 'tenantId'], $insertSelectRaw['select'], true), 'INSERT SELECT tenant projection is missing.');
expect(in_array(["VALUE:{$tenantA->serviceId}", 'serviceId'], $insertSelectRaw['select'], true), 'INSERT SELECT service projection is missing.');

foreach ([
    Update::fromRaw(['from' => 'Account', 'set' => ['name' => 'Changed']]),
    Delete::fromRaw(['from' => 'Account']),
] as $writeQuery) {
    $raw = $store->runWith($tenantA, fn () => $processor->process($writeQuery)->getRaw());
    expect($raw['whereClause']['tenantId'] === $tenantA->tenantId, 'Write scope is missing.');
    expect($raw['whereClause']['serviceId'] === $tenantA->serviceId, 'Write service scope is missing.');
}

$joined = $store->runWith($tenantA, fn () => $processor->process(Select::fromRaw([
    'from' => 'Account',
    'fromAlias' => 'a',
    'joins' => [['Contact', 'c', ['c.id:' => 'a.contactId']]],
]))->getRaw());
expect($joined['whereClause']['a.tenantId'] === $tenantA->tenantId, 'Root alias is not scoped.');
expect($joined['whereClause']['a.serviceId'] === $tenantA->serviceId, 'Root alias service is not scoped.');
expect($joined['joins'][0][2][0]['c.tenantId'] === $tenantA->tenantId, 'Joined entity is not scoped.');
expect($joined['joins'][0][2][0]['c.serviceId'] === $tenantA->serviceId, 'Joined entity service is not scoped.');

$relationJoin = $store->runWith($tenantA, fn () => $processor->process(Select::fromRaw([
    'from' => 'Account',
    'joins' => ['contacts', ['contacts', 'contactAlias']],
]))->getRaw());
expect($relationJoin['joins'][0] === 'contacts', 'String relationship join shape must be preserved.');
expect(
    $relationJoin['joins'][1] === ['contacts', 'contactAlias'],
    'Array relationship join shape must be preserved.'
);

foreach (['DashboardTemplate', 'Export', 'Job'] as $moduleEntity) {
    $raw = $store->runWith($tenantA, fn () => $processor->process(
        Select::fromRaw(['from' => $moduleEntity])
    )->getRaw());
    expect($raw['whereClause']['tenantId'] === $tenantA->tenantId, "{$moduleEntity} is not scoped.");
    expect($raw['whereClause']['serviceId'] === $tenantA->serviceId, "{$moduleEntity} service is not scoped.");
}

$globalRead = $store->runWith($tenantA, fn () => $processor->process(
    Select::fromRaw(['from' => 'SystemData'])
)->getRaw());
expect(!isset($globalRead['whereClause']), 'Platform reference read should remain global.');
expectException(TenantScopeViolation::class, fn () => $store->runWith($tenantA, fn () =>
    $processor->process(Update::fromRaw(['from' => 'SystemData', 'set' => ['value' => 'x']]))
));

$platformRaw = $store->runAsPlatform(fn () => $processor->process(
    Select::fromRaw(['from' => 'Account'])
)->getRaw());
expect(!isset($platformRaw['whereClause']), 'Audited platform execution should bypass tenant scope.');

$keyA = $store->runWith($tenantA, fn () => $resourceKey->for('cache', 'dashboard/summary'));
$keyB = $store->runWith($tenantB, fn () => $resourceKey->for('cache', 'dashboard/summary'));
expect($keyA !== $keyB, 'Non-database resource keys must be tenant separated.');

echo "Tenant runtime tests passed.\n";
