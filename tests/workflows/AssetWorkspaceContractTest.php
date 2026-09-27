<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$migration = $read('database/shared/migrations/0049_add_asset_governance.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$service = $read('espocrm/custom/Espo/Custom/Tools/Asset/AssetWorkspaceService.php');
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$view = $read('espocrm/client/custom/src/views/asset/workspace.js');
$template = $read('espocrm/client/custom/res/templates/asset/workspace.tpl');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$storage = $read('espocrm/custom/Espo/Custom/Core/FileStorage/Storages/CloudflareR2.php');
foreach (['nexa_asset_profile', 'nexa_asset_version', 'nexa_asset_event'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Asset migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1 && ($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be service owned.");
}
foreach (['/Nexa/assets/workspace', '/Nexa/assets/:id/version', '/Nexa/assets/:id/download', '/Nexa/assets/:id/archive', '/Nexa/assets/:id/restore'] as $route) $assert(str_contains($routes, $route), "Asset API route {$route} is missing.");
$assert(str_contains($service, "->get('Document')->create") && str_contains($service, 'current_attachment_id'), 'Assets must retain native Document and Attachment records.');
$assert(str_contains($service, 'tenant_id=?') && str_contains($service, 'service_id=?'), 'Asset access must be tenant and service scoped.');
$assert(str_contains($service, 'nexa_asset_version') && str_contains($migration, 'checksum_sha256'), 'Asset replacement must preserve governed version history.');
$assert(str_contains($service, 'download_count') && str_contains($service, '$this->event($context, $id, \'download\')'), 'Asset downloads must be audited.');
$assert(str_contains($storage, "return \$tenantId . '/' . \$attachment->getSourceId()"), 'Cloudflare R2 object keys must remain tenant prefixed.');
$assert(str_contains($template, 'data-workspace-table="assets"') && str_contains($template, 'data-asset-scroll'), 'Assets must use the standard scrollable workspace table.');
$assert(str_contains($view, 'custom:workspace-table') && str_contains($view, 'loadMore') && str_contains($view, 'data-table-sort'), 'Assets must support reusable column controls, sorting and incremental loading.');
$assert(str_contains($registry, "'#NexaAssets'"), 'Content & Assets must be an active Marketing navigation surface.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Asset service must not hard-code demo tenants.');
echo "Asset workspace contracts passed.\n";
