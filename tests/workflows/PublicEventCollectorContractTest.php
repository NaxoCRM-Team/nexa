<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);

$migration = $read('database/shared/migrations/0059_add_tracking_sources.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, 512, JSON_THROW_ON_ERROR);
$collector = $read('espocrm/custom/Espo/Custom/Tools/Event/PublicEventCollectorService.php');
$admin = $read('espocrm/custom/Espo/Custom/Tools/Event/TrackingSourceService.php');
$tracker = $read('espocrm/client/custom/nexa-tracker.js');
$view = $read('espocrm/client/custom/src/views/tracking/workspace.js');
$template = $read('espocrm/client/custom/res/templates/tracking/workspace.tpl');
$navigation = $read('espocrm/client/custom/src/product-surface-registry.js');

$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `nexa_tracking_source`'), 'Tracking-source migration is missing.');
foreach (['public_key', 'integration_mode', 'allowed_origins_json', 'tenant_id', 'service_id'] as $column) {
    $assert(str_contains($migration, "`{$column}`"), "Tracking-source schema is missing {$column}.");
}
$entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === 'nexa_tracking_source'));
$assert(count($entry) === 1 && ($entry[0]['classification'] ?? null) === 'serviceOwned', 'Tracking sources must be service owned.');
$assert(($entry[0]['serviceScope'] ?? null) === 'required', 'Tracking sources must require service scope.');
$public = array_values(array_filter($routes, static fn (array $route): bool => ($route['route'] ?? '') === '/Nexa/public/events/:key'));
$assert(count($public) === 2, 'Public collector must expose POST and OPTIONS routes.');
$assert(count(array_filter($public, static fn (array $route): bool => ($route['noAuth'] ?? false) === true)) === 2, 'Public collector routes must be unauthenticated and token scoped.');
foreach (['contactId', 'accountId', 'identityEvidence', 'normalizeOrigin', 'managedConsent', 'externalConsent', 'event-collector:'] as $marker) {
    $assert(str_contains($collector, $marker), "Collector guard {$marker} is missing.");
}
$assert(!str_contains($collector, "Access-Control-Allow-Origin', '*'"), 'Collector CORS must never use a wildcard origin.');
$assert(str_contains($admin, 'bin2hex(random_bytes(24))'), 'Tracking keys must be generated cryptographically.');
$assert(str_contains($admin, 's.tenant_id=? AND s.service_id=?'), 'Tracking administration must scope tenant and service queries.');
$assert(str_contains($tracker, 'window.NexaTracking') && str_contains($tracker, 'window.NexaConsent'), 'Browser tracker must expose an API and integrate with consent.');
$assert(str_contains($tracker, "credentials: 'omit'") && str_contains($tracker, 'page.viewed'), 'Browser tracker must omit credentials and record page views.');
$assert(str_contains($view, 'rotateKey') && str_contains($view, 'copyEmbed') && str_contains($view, 'testSource'), 'Tracking workspace must support diagnostics, key rotation and embed-code copy.');
$assert(str_contains($template, 'Approved website origins') && str_contains($template, 'Browser events never choose a CRM identity'), 'Tracking workspace must explain its trust boundary.');
$assert(str_contains($navigation, "'#NexaTracking'"), 'Tracking & Events navigation route is not active.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $collector . $admin), 'Tracking services must not hard-code demo tenants.');

echo "Public event collector contracts passed.\n";
