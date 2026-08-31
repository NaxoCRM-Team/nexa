<?php

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$service = $read('espocrm/custom/Espo/Custom/Tools/Activity/ActivityWorkspaceService.php');
$view = $read('espocrm/client/custom/src/views/activity/workspace.js');
$template = $read('espocrm/client/custom/res/templates/activity/workspace.tpl');
$controller = $read('espocrm/client/custom/src/controllers/nexa-activity.js');
$calendarClient = $read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Calendar.json');
$documentClient = $read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Document.json');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$projectService = $read('espocrm/custom/Espo/Custom/Tools/Project/ProjectService.php');
$projectMigration = $read('database/shared/migrations/0041_add_project_collaboration.sql');
$task = $read('espocrm/application/Espo/Modules/Crm/Resources/metadata/clientDefs/Task.json');
$meeting = $read('espocrm/application/Espo/Modules/Crm/Resources/metadata/clientDefs/Meeting.json');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert((bool) array_filter($routes, static fn (array $route): bool =>
    $route['route'] === '/Nexa/activity/workspace' &&
    $route['method'] === 'get' &&
    str_ends_with($route['actionClassName'], 'Activity\\Api\\GetWorkspace')
), 'The activity workspace API route is missing.');
$assert(str_contains($service, "private const TYPES = ['Task', 'Meeting', 'Call']"), 'The workspace must aggregate native activity entities.');
$assert(str_contains($service, 'tenant_id = :tenant') && str_contains($service, 'service_id = :service'), 'Activity candidates must be tenant and service scoped.');
$assert(str_contains($service, '$this->acl->check($entity, Table::ACTION_READ)'), 'Every candidate record must pass native record ACL.');
$assert(str_contains($service, '$this->acl->checkReadAll($scope)'), 'Owner discovery must respect read-all permission.');
$assert(str_contains($task, 'reminders-handler') && str_contains($meeting, 'scheduler'), 'Native reminders and meeting scheduling must remain authoritative.');
$assert(str_contains($registry, "'#NexaActivity/agenda'"), 'The delivered Activity Workspace must be active in Sales navigation.');
$assert(str_contains($template, 'data-activity-state="loading"') && str_contains($template, 'data-activity-state="denied"'), 'Loading and permission states are required.');
$assert(str_contains($view, 'renderAgenda()') && !str_contains($view, 'renderCalendar()') && !str_contains($view, 'renderDocuments()'), 'Activity Workspace must provide Agenda without duplicating Calendar or Documents.');
$assert(!str_contains($template, 'data-calendar-grid') && !str_contains($template, 'data-document-list'), 'Duplicate Calendar and Document surfaces must not return to Activity Workspace.');
$assert(str_contains($controller, "navigate('#Calendar'") && str_contains($controller, "navigate('#Document'"), 'Legacy activity URLs must redirect to the authoritative native modules.');
$assert(str_contains($calendarClient, 'custom:controllers/calendar'), 'Native Calendar must use the Nexa presentation wrapper.');
$assert(str_contains($documentClient, 'custom:views/document/list'), 'Native Documents must use the Nexa presentation wrapper.');
$assert(str_contains($view, 'Intl.DateTimeFormat'), 'Activity dates must be locale aware.');
$assert(str_contains($projectMigration, 'CREATE TABLE IF NOT EXISTS `nexa_project`'), 'The tenant-owned Project table is missing.');
$assert(str_contains($projectMigration, 'nexa_project_task') && str_contains($projectMigration, 'nexa_project_document'), 'Projects must associate native Tasks and Documents.');
$assert(str_contains($projectMigration, 'nexa_project_update') && str_contains($projectMigration, 'mentioned_user_ids_json'), 'Project updates and structured mentions are required.');
$assert(str_contains($projectService, 'tenant_id=? AND service_id=?'), 'Project reads and writes must use tenant and service scope.');
$assert(str_contains($projectService, "checkReadAll('Task')"), 'Project visibility must follow native work-management ACL.');
$assert(str_contains($projectService, "in_array(\$type, ['Task', 'Document'], true)"), 'Only native Tasks and Documents may be connected to projects.');
$assert(str_contains($projectService, '$this->acl->check($entity, Table::ACTION_READ)'), 'Connected project records must pass native record ACL.');
$assert(str_contains($projectService, 'INSERT IGNORE INTO {$table}') && str_contains($projectService, 'tenant_id=? AND service_id=? AND project_id=?'), 'Project links must remain tenant and service scoped.');
$assert((bool) array_filter($routes, static fn (array $route): bool =>
    $route['route'] === '/Nexa/projects/:id/links' && $route['method'] === 'post'
), 'The project association API route is missing.');
$assert(str_contains($view, 'saveProject(event)') && str_contains($view, 'archiveProject(event)'), 'Project create, edit and archive interactions are required.');
$assert(str_contains($view, "'views/modals/select-records'") && str_contains($view, 'refreshProjectDetail(id)'), 'Project associations must reuse the native record picker and refresh in place.');

echo "Activity workspace contracts passed.\n";
