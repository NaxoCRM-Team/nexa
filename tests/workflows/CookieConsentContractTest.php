<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('database/shared/migrations/0045_add_cookie_consent_governance.sql');
$modeMigration = $read('database/shared/migrations/0046_add_cookie_banner_integration_mode.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$service = $read('espocrm/custom/Espo/Custom/Tools/Consent/CookieConsentService.php');
$template = $read('espocrm/client/custom/res/templates/consent/workspace.tpl');
$view = $read('espocrm/client/custom/src/views/consent/workspace.js');
$embed = $read('espocrm/client/custom/nexa-cookie-consent.js');

foreach (['nexa_cookie_banner', 'nexa_cookie_category', 'nexa_cookie_receipt'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Cookie migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1, "Ownership manifest is missing {$table}.");
    $assert(($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be service owned.");
    $assert(($entry[0]['serviceScope'] ?? null) === 'required', "{$table} must require service scope.");
}
foreach (['policy_version', 'categories_json', 'configuration_hash', 'global_privacy_control', 'receipt_key'] as $column) {
    $assert(str_contains($migration, "`{$column}`"), "Cookie evidence schema is missing {$column}.");
}
foreach (['/Nexa/consent/cookies/workspace', '/Nexa/consent/cookies/settings', '/Nexa/public/cookies/config/:key', '/Nexa/public/cookies/receipts'] as $route) {
    $assert(str_contains($routes, $route), "Cookie API route {$route} is missing.");
}
$routeData = json_decode($routes, true, 512, JSON_THROW_ON_ERROR);
$publicRoutes = array_values(array_filter($routeData, static fn (array $route): bool => str_starts_with((string) ($route['route'] ?? ''), '/Nexa/public/cookies/')));
$assert(count($publicRoutes) === 2 && count(array_filter($publicRoutes, static fn (array $route): bool => ($route['noAuth'] ?? false) === true)) === 2, 'Cookie configuration and receipts must be public token-scoped endpoints.');
foreach (['tenant_id=? AND service_id=?', 'public_key', 'INSERT IGNORE INTO nexa_cookie_receipt', 'globalPrivacyControl'] as $marker) {
    $assert(str_contains($service, $marker), "Cookie service is missing {$marker}.");
}
$assert(str_contains($service, 'shouldDisplay') && str_contains($service, "'eu_uk'"), 'Regional cookie banner enforcement is missing.');
$assert(str_contains($modeMigration, '`integration_mode`') && str_contains($modeMigration, "'existing_banner'"), 'Existing-banner integration mode migration is missing.');
$assert(str_contains($template, 'data-consent-view="cookies"') && str_contains($template, 'data-cookie-preview'), 'Consent workspace must expose cookie configuration and preview.');
$assert(str_contains($view, 'saveCookieSettings') && str_contains($view, 'copyCookieEmbed'), 'Cookie administration must save settings and expose installation code.');
$assert(str_contains($embed, "window.dispatchEvent(new CustomEvent('nexa:cookie-consent'"), 'Embed runtime must expose category choices to tenant websites.');
$assert(str_contains($embed, 'navigator.globalPrivacyControl'), 'Embed runtime must honor Global Privacy Control.');
$assert(str_contains($embed, 'window.NexaConsent') && str_contains($embed, 'sync: input =>'), 'Existing consent banners must be able to synchronize choices through NexaConsent.sync.');
$assert(str_contains($embed, "requestedMode === 'existing_banner'"), 'Existing-banner mode must suppress the Nexa-managed banner.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Cookie service must not hard-code demo tenants.');

echo "Cookie consent governance contracts passed.\n";
