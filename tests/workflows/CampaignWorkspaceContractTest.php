<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$migration = $read('database/shared/migrations/0055_add_campaign_enrollment_governance.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$service = $read('espocrm/custom/Espo/Custom/Tools/Campaign/CampaignWorkspaceService.php');
$template = $read('espocrm/client/custom/res/templates/campaign/workspace.tpl');
$view = $read('espocrm/client/custom/src/views/campaign/workspace.js');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$job = $read('espocrm/custom/Espo/Custom/Jobs/RecalculateDynamicSegments.php');
foreach (['nexa_campaign_profile','nexa_campaign_version','nexa_campaign_enrollment','nexa_campaign_event'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Campaign migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1 && ($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be service owned.");
}
foreach (['/Nexa/campaigns/workspace','/Nexa/campaigns/preview','/Nexa/campaigns/:id/enroll','/Nexa/campaigns/:id/status'] as $route) $assert(str_contains($routes, $route), "Campaign route {$route} is missing.");
$assert(str_contains($service, "->get('Campaign')->create") && str_contains($service, 'campaign_target_list'), 'Campaigns must retain native Campaign and Target List relationships.');
$assert(str_contains($service, 'nexa_consent_state') && str_contains($service, 'marketing_status'), 'Campaign audience evaluation must enforce consent and marketing eligibility.');
$assert(str_contains($service, 'tenant_id=?') && str_contains($service, 'service_id=?'), 'Campaign operations must scope tenant and service ownership.');
$assert(str_contains($service, 'contact_enrolled') && str_contains($service, 'contact_suppressed'), 'Campaign enrollment decisions must be auditable.');
$assert(str_contains($service, 'contact_exited') && str_contains($service, 'recalculateContinuous') && str_contains($job, 'recalculateContinuous'), 'Continuous campaigns must be reconciled by the segment scheduler and record audience exits.');
$assert(str_contains($template, 'data-campaign-search') && str_contains($view, 'renderCampaigns'), 'Campaigns must provide live search.');
$assert(str_contains($template, 'Check audience') && str_contains($view, 'reasonCounts'), 'Campaign preview must explain suppression before activation.');
$assert(str_contains($view, 'no messages will be sent') && str_contains($template, 'data-sending-message'), 'The Phase 4 no-send boundary must be explicit.');
$assert(str_contains($registry, "'#NexaCampaigns'") && !preg_match('/\x27Campaign\x27, \[\x27nexa-lists-segments/', $registry), 'Marketing navigation must use the governed campaign workspace once.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Campaign service must not hard-code demo tenants.');
echo "Campaign workspace contracts passed.\n";
