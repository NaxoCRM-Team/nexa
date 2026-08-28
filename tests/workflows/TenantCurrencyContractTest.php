<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException("Missing tenant currency contract: {$path}");
    return $value;
};
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$migration = $read('database/shared/migrations/0039_add_tenant_currency_ownership.sql');
$providerMigration = $read('database/shared/migrations/0040_add_currency_rate_provider.sql');
$overlay = $read('espocrm/application/Espo/Core/Tenant/TenantCurrencyConfigOverlay.php');
$application = $read('espocrm/application/Espo/Core/Application.php');
$jobs = $read('espocrm/application/Espo/Core/Job/JobRunner.php');
$service = $read('espocrm/custom/Espo/Custom/Tools/Currency/TenantCurrencyService.php');
$provider = $read('espocrm/custom/Espo/Custom/Tools/Currency/FrankfurterRateProvider.php');
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$view = $read('espocrm/client/custom/src/views/currency/admin.js');
$sales = $read('espocrm/custom/Espo/Custom/Tools/Sales/SalesWorkspaceService.php');
$productTemplate = $read('espocrm/client/custom/res/templates/sales/workspace.tpl');
$opportunity = $read('espocrm/client/custom/src/views/opportunity/record/detail-workspace.js');

foreach (['nexa_tenant_currency_profile', 'nexa_tenant_currency_rate', 'nexa_tenant_currency_history'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Currency migration is missing {$table}.");
}
$assert(str_contains($migration, "SELECT `tenant_id`, `service_id`, 'USD', 'USD'"), 'Existing tenant services must receive USD defaults.');
$assert(str_contains($providerMigration, 'rate_mode') && str_contains($providerMigration, 'rates_effective_date'), 'Rate source and effective-date ownership are missing.');
$assert(str_contains($overlay, "'currencyList', 'defaultCurrency', 'baseCurrency', 'currencyRates'"), 'Native Config must receive the complete tenant currency profile.');
$assert(str_contains($application, 'TenantCurrencyConfigOverlay') && str_contains($jobs, 'TenantCurrencyConfigOverlay'), 'Web requests and background jobs must both apply tenant currency context.');
$assert(str_contains($service, 'Only a tenant administrator') && str_contains($service, 'rejectCurrenciesInUse'), 'Currency changes need tenant-admin authorization and safe-disable validation.');
$assert(str_contains($routes, '/Nexa/currency/settings'), 'Tenant currency API routes are missing.');
$assert(str_contains($routes, '/Nexa/currency/rates/preview'), 'Reference-rate preview route is missing.');
$assert(str_contains($provider, 'api.frankfurter.dev/v2/rates') && str_contains($provider, '1 / $providerRate'), 'Automatic provider rates must be fetched and converted into native EspoCRM rate semantics.');
$assert(str_contains($service, "['automatic', 'manual']") && str_contains($service, 'WHERE NOT EXISTS'), 'Tenants need automatic/manual rates without USD being re-added after removal.');
$assert(str_contains($view, "getMetadata") || str_contains($service, "['app', 'currency', 'list']"), 'The native EspoCRM currency catalogue must be reused.');
$assert(str_contains($productTemplate, 'data-product-currency'), 'Product currency must be a controlled selector.');
$assert(str_contains($opportunity, 'Opportunity currency') && str_contains($opportunity, 'readonly'), 'Line-item currency must inherit the Opportunity currency.');
$assert(str_contains($sales, 'currencySettings') && str_contains($sales, 'Opportunity line items must use'), 'Sales APIs must expose and enforce tenant currency settings.');

echo "Tenant currency ownership contracts passed.\n";
