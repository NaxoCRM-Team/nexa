<?php

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$entity = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Case.json'), true, flags: JSON_THROW_ON_ERROR);
$client = $read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Case.json');
$portalList = $read('espocrm/custom/Espo/Custom/Resources/layouts/Case/listPortal.json');
$portalDetail = $read('espocrm/custom/Espo/Custom/Resources/layouts/Case/detailPortal.json');
$view = $read('espocrm/client/custom/src/views/case/record/detail-workspace.js');
$list = $read('espocrm/client/custom/src/views/case/list-v2.js');
$search = $read('espocrm/client/custom/src/views/case/record/search-live.js');
$service = $read('espocrm/custom/Espo/Custom/Tools/CaseService/CaseSlaService.php');
$migration = $read('database/shared/migrations/0042_add_case_service_sla.sql');
$nativeBeforeCreate = $read('espocrm/application/Espo/Modules/Crm/Classes/RecordHooks/Case/BeforeCreate.php');
$nativeInbound = $read('espocrm/application/Espo/Core/Mail/Account/GroupAccount/Hooks/AfterFetch.php');
$nativePortalAcl = $read('espocrm/application/Espo/Modules/Crm/Classes/AclPortal/Case/OwnershipChecker.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
};

$assert(!isset($entity['fields']['assignedUsers']) && !isset($entity['fields']['assignedUser']), 'Case must not disable or compete with native assignedUser ownership.');
$assert(isset($entity['fields']['slaStatus'], $entity['fields']['resolutionDueAt'], $entity['fields']['category']), 'Missing Case service fields.');
$assert(isset($entity['links']['opportunity']), 'Cases must support native Opportunity commercial context.');
$assert(str_contains($client, 'custom:views/case/list-v2') && str_contains($client, 'custom:views/case/record/detail-workspace'), 'Case list/detail redesign is not registered.');
$assert(str_contains($portalList, 'slaStatus') && str_contains($portalDetail, 'Response commitment'), 'Portal Case SLA layouts are missing.');
$assert(str_contains($view, 'isPortalUser()') && str_contains($view, "parentType:'Case'"), 'One native-backed view must adapt for portal users and related activity.');
$assert(str_contains($search, 'queueLiveSearch') && str_contains($search, 'collection.fetch'), 'Case search must provide immediate loaded-row and server search.');
$assert(str_contains($list, 'SLA policies') && str_contains($list, 'getUser().isAdmin()'), 'Tenant-admin SLA configuration is missing.');
$assert(str_contains($migration, 'nexa_case_sla_policy') && str_contains($migration, '`tenant_id`') && str_contains($migration, '`service_id`'), 'SLA policy storage must be tenant and service owned.');
$assert(str_contains($migration, "eu.entity_type = 'Case'") && str_contains($migration, 'assigned_user_id'), 'Legacy Case assignment data must be reconciled into native ownership.');
$assert(str_contains($service, 'tenant_id=? AND service_id=?') && str_contains($service, 'Table::ACTION_EDIT'), 'Case SLA APIs must enforce tenant/service and native ACL.');
$assert(str_contains($nativeBeforeCreate, '$this->user->isPortal()') && str_contains($nativeBeforeCreate, 'setContact'), 'Native Portal Case ownership initialization was not retained.');
$assert(str_contains($nativeInbound, "caseDistribution") && str_contains($nativeInbound, "assignedUserId"), 'Native inbound email distribution must remain authoritative.');
$assert(str_contains($nativePortalAcl, 'DefaultOwnershipChecker') && str_contains($nativePortalAcl, 'isInternal'), 'Native Portal Case ownership ACL must remain authoritative.');
$assert((bool) array_filter($routes, static fn (array $route): bool => $route['route'] === '/Nexa/cases/:id/response' && $route['method'] === 'post'), 'Record-response API route is missing.');
$assert((bool) array_filter($routes, static fn (array $route): bool => $route['route'] === '/Nexa/cases/sla-policies' && $route['method'] === 'put'), 'SLA policy API route is missing.');
foreach (['isolation-alpha', 'isolation-beta', 'tenant-a', 'tenant-b'] as $literal) {
    $assert(!str_contains($service, $literal) && !str_contains($view, $literal), "Case runtime hardcodes synthetic tenant {$literal}.");
}

echo "Case and Portal workflow contracts passed.\n";
