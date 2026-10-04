<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('database/shared/migrations/0062_add_marketing_email_governance.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$service = $read('espocrm/custom/Espo/Custom/Tools/MarketingEmail/MarketingEmailWorkspaceService.php');
$view = $read('espocrm/client/custom/src/views/marketing-email/workspace.js');
$template = $read('espocrm/client/custom/res/templates/marketing-email/workspace.tpl');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$audit = $read('docs/architecture/phase-6-native-email-capability-audit.md');

foreach (['nexa_marketing_email_profile', 'nexa_marketing_email_version', 'nexa_marketing_email_event'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Marketing email migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1 && ($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be service owned.");
}

foreach (['/Nexa/marketing-emails/workspace', '/Nexa/marketing-emails', '/Nexa/marketing-emails/:id', '/Nexa/marketing-emails/:id/transition'] as $route) {
    $assert(str_contains($routes, $route), "Marketing email route {$route} is missing.");
}

$assert(str_contains($service, "get('EmailTemplate')->create") && str_contains($service, "get('MassEmail')->create"), 'Marketing email must retain native EmailTemplate and MassEmail persistence.');
$assert(str_contains($service, 'targetListsIds') && str_contains($service, 'excludingTargetListsIds'), 'Marketing email must retain native campaign audience relationships.');
$assert(str_contains($service, 'nexa_campaign_profile') && str_contains($service, 'purpose_id'), 'Marketing email must inherit governed campaign audience and consent purpose.');
$assert(str_contains($service, 'tenant_id=?') && str_contains($service, 'service_id=?'), 'Marketing email queries must scope tenant and service ownership.');
$assert(str_contains($service, "'draft'") && str_contains($service, "'review'") && str_contains($service, "'approved'"), 'Draft, review and approval states must be governed.');
$assert(str_contains($service, "'deliveryEnabled' => false"), 'Delivery must remain locked until provider and domain governance is implemented.');
$assert(str_contains($view, 'data-email-search') && str_contains($view, 'renderEmails'), 'Marketing email must provide live search.');
$assert(str_contains($template, 'Delivery remains locked') && str_contains($template, 'Create marketing email'), 'The workspace must explain delivery readiness and support draft creation.');
$assert(str_contains($registry, "'#NexaMarketingEmail'"), 'Marketing navigation must open the governed marketing email workspace.');
$assert(str_contains($audit, 'EmailTemplate') && str_contains($audit, 'MassEmail') && str_contains($audit, 'Postal'), 'The native capability audit must record native ownership and provider boundaries.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Marketing email service must not hard-code demo tenants.');

echo "Marketing email workspace contracts passed.\n";
