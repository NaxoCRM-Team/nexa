<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Currency\Converter;
use Espo\Core\Field\Currency;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\TenantCurrencyConfigOverlay;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Currency\TenantCurrencyService;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-currency-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-currency-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$factory = $container->getByClass(InjectableFactory::class);
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$overlay = $factory->create(TenantCurrencyConfigOverlay::class);
$config = $container->getByClass(Config::class);
$converter = $factory->create(Converter::class);
$service = $factory->create(TenantCurrencyService::class);
$pdo = $entityManager->getPDO();
$pdo->beginTransaction();

try {
    $store->runWith($tenantA, fn () => $service->save((object) [
        'baseCurrency' => 'GBP', 'defaultCurrency' => 'GBP',
        'rateMode' => 'manual',
        'enabledCurrencies' => ['GBP', 'USD', 'EUR'],
        'rates' => (object) ['GBP' => 1, 'USD' => 0.76, 'EUR' => 0.86],
    ]));
    $store->runWith($tenantB, fn () => $service->save((object) [
        'baseCurrency' => 'USD', 'defaultCurrency' => 'USD',
        'rateMode' => 'manual',
        'enabledCurrencies' => ['USD'], 'rates' => (object) ['USD' => 1],
    ]));

    $alpha = $store->runWith($tenantA, fn (): array => $service->get());
    $beta = $store->runWith($tenantB, fn (): array => $service->get());
    $assert($alpha['baseCurrency'] === 'GBP' && $alpha['enabledCurrencies'] === ['GBP', 'USD', 'EUR'], 'Tenant A currency profile was not saved independently.');
    $assert($beta['baseCurrency'] === 'USD' && $beta['enabledCurrencies'] === ['USD'], 'Tenant B currency profile was changed by Tenant A.');

    $alphaNative = $store->runWith($tenantA, fn (): array => $overlay->run($tenantA, fn (): array => [
        'base' => $config->get('baseCurrency'),
        'default' => $config->get('defaultCurrency'),
        'list' => $config->get('currencyList'),
        'converted' => $converter->convert(new Currency('100', 'USD'), 'GBP')->getAmountAsString(),
    ]));
    $betaNative = $store->runWith($tenantB, fn (): array => $overlay->run($tenantB, fn (): array => [
        'base' => $config->get('baseCurrency'), 'list' => $config->get('currencyList'),
    ]));
    $assert($alphaNative['base'] === 'GBP' && $alphaNative['default'] === 'GBP' && $alphaNative['list'] === ['GBP', 'USD', 'EUR'], 'Native EspoCRM Config did not receive Tenant A currencies.');
    $assert(abs((float) $alphaNative['converted'] - 76.0) < 0.001, 'Native EspoCRM conversion did not use Tenant A rates.');
    $assert($betaNative['base'] === 'USD' && $betaNative['list'] === ['USD'], 'Native EspoCRM Config leaked Tenant A currencies to Tenant B.');

    $history = $pdo->prepare('SELECT tenant_id, COUNT(*) AS quantity FROM nexa_tenant_currency_history WHERE tenant_id IN (?, ?) GROUP BY tenant_id');
    $history->execute([$tenantA->tenantId, $tenantB->tenantId]);
    $counts = array_column($history->fetchAll(PDO::FETCH_ASSOC), 'quantity', 'tenant_id');
    $assert((int) ($counts[$tenantA->tenantId] ?? 0) === 1 && (int) ($counts[$tenantB->tenantId] ?? 0) === 1, 'Currency changes were not audited independently by tenant.');
    echo "Tenant currency isolation tests passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
