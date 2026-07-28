<?php

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/espocrm/custom/Espo/Custom/Tools/Dashboard/TenantDashboardService.php');
$route = json_decode(file_get_contents($root . '/espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$clientDefs = json_decode(file_get_contents($root . '/espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Home.json'), true, flags: JSON_THROW_ON_ERROR);
$template = file_get_contents($root . '/espocrm/client/custom/res/templates/tenant-dashboard.tpl');

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
$dashboardRoute = array_values(array_filter($route, static fn (array $item): bool => $item['route'] === '/Nexa/dashboard/summary'));
$assert(count($dashboardRoute) === 1 && !isset($dashboardRoute[0]['noAuth']), 'Dashboard summary must be authenticated.');
$assert(($clientDefs['view'] ?? null) === 'custom:views/tenant-dashboard', 'Home must use the Nexa tenant dashboard view.');
foreach (['loading', 'empty', 'error', 'denied', 'ready'] as $state) {
    $assert(str_contains($template, 'data-dashboard-state="' . $state . '"'), "Dashboard {$state} state is missing.");
}

fwrite(STDOUT, "Tenant dashboard contract suite passed.\n");
