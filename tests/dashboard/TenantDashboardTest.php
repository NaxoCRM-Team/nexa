<?php

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/espocrm/custom/Espo/Custom/Tools/Dashboard/TenantDashboardService.php');
$route = json_decode(file_get_contents($root . '/espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$clientDefs = json_decode(file_get_contents($root . '/espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Home.json'), true, flags: JSON_THROW_ON_ERROR);
$template = file_get_contents($root . '/espocrm/client/custom/res/templates/tenant-dashboard.tpl');
$viewPath = $root . '/espocrm/client/custom/src/views/tenant-dashboard.js';
$demoProvisioner = file_get_contents($root . '/espocrm/bin/provision-demo-tenants.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(substr_count($service, 'tenant_id = :tenantId') >= 4, 'Dashboard queries must explicitly scope tenant data.');
$assert(substr_count($service, 'service_id = :serviceId') >= 4, 'Dashboard queries must explicitly scope CRM service data.');
$assert(str_contains($service, "isEnabled('crm')"), 'Dashboard must enforce CRM entitlement.');
$assert(str_contains($service, 'checkReadAll'), 'Dashboard aggregates must require tenant-wide read access.');
$assert(str_contains($service, "get('baseCurrency')"), 'Dashboard currency must follow application configuration.');
$assert(!str_contains($demoProvisioner, "'amount_currency' => 'GBP'"), 'Demo opportunities must not hard-code a currency.');
$assert(str_contains($demoProvisioner, "'stage' => 'Closed Won'"), 'Demo data must include won sales for trend charts.');
$dashboardRoute = array_values(array_filter($route, static fn (array $item): bool => $item['route'] === '/Nexa/dashboard/summary'));
$assert(count($dashboardRoute) === 1 && !isset($dashboardRoute[0]['noAuth']), 'Dashboard summary must be authenticated.');
$assert(($clientDefs['view'] ?? null) === 'custom:views/tenant-dashboard', 'Home must use the Nexa tenant dashboard view.');
$assert(is_file($viewPath), 'Custom dashboard view must use EspoCRM client/custom/src module resolution.');
$assert(
    str_contains((string) file_get_contents($viewPath), "define('custom:views/tenant-dashboard'"),
    'Custom dashboard module ID must match the Home client definition.'
);
$view = (string) file_get_contents($viewPath);
foreach (['Overview', 'Sales', 'Schedule'] as $tabName) {
    $assert(str_contains($view, "name: '{$tabName}'"), "Default dashboard tab {$tabName} is missing.");
}
foreach (['Activities', 'Stream', 'SalesPipeline', 'OpportunitiesByStage', 'SalesByMonth', 'Calendar'] as $dashlet) {
    $assert(str_contains($view, "name: '{$dashlet}'"), "Default dashboard dashlet {$dashlet} is missing.");
}
$assert(str_contains($view, 'isDashletAvailable'), 'Default dashboard widgets must be permission aware.');
$assert(str_contains($view, 'isNexaDashboardLayoutV1'), 'The previous Nexa default layout must be migrated once.');
$assert(str_contains($view, 'migrateNexaDashboardLayout'), 'Custom dashboard tabs must survive the default-layout migration.');
foreach ([
    "{id: 'nexa-activities', name: 'Activities', x: 0, y: 0, width: 2, height: 2}",
    "{id: 'nexa-sales-pipeline', name: 'SalesPipeline', x: 2, y: 0, width: 2, height: 2}",
    "{id: 'nexa-opportunities-stage', name: 'OpportunitiesByStage', x: 0, y: 2, width: 2, height: 2}",
    "{id: 'nexa-sales-trend', name: 'SalesByMonth', x: 0, y: 4, width: 4, height: 2}",
] as $geometry) {
    $assert(str_contains($view, $geometry), 'Dashboard defaults must use aligned, readable widget geometry.');
}
$assert(
    str_contains($view, "this.element.classList.add('nexa-dashboard')"),
    'Dashboard styling must be applied to the existing Home view element.'
);
$assert(
    !str_starts_with(trim($template), '<div class="nexa-dashboard"'),
    'Dashboard template must not wrap the Home view or core dashlet selectors cannot mount.'
);
$assert(
    (bool) preg_match(
        '/<\/section>\s*<div\b(?=[^>]*\bid="nexa-dashboard-widgets")(?=[^>]*\bclass="[^"]*\bdashlets\b)[^>]*>/',
        $template
    ),
    'Dashlet grid must be a direct dashboard child for EspoCRM grid initialization.'
);
foreach (['loading', 'empty', 'error', 'denied', 'ready'] as $state) {
    $assert(str_contains($template, 'data-dashboard-state="' . $state . '"'), "Dashboard {$state} state is missing.");
}

fwrite(STDOUT, "Tenant dashboard contract suite passed.\n");
