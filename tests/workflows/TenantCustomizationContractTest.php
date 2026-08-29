<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) throw new RuntimeException("Missing customization contract: {$path}");
    return $contents;
};
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$migration = $read('database/shared/migrations/0032_add_tenant_customization_engine.sql');
$preferenceMigration = $read('database/shared/migrations/0033_add_tenant_property_preferences.sql');
$service = $read('espocrm/custom/Espo/Custom/Tools/Customization/CustomizationService.php');
$helper = $read('espocrm/client/custom/src/helpers/custom-properties.js');
$admin = $read('espocrm/client/custom/src/views/customization/admin.js');
$runtime = $read('espocrm/client/custom/src/views/customization/runtime.js');
$runtimeTemplate = $read('espocrm/client/custom/res/templates/customization/runtime.tpl');
$propertyFilter = $read('espocrm/client/custom/src/views/fields/custom-property-filter.js');
$textFilter = $read('espocrm/custom/Espo/Custom/Classes/Select/Common/CustomPropertyTextFilter.php');
$whereFilter = $read('espocrm/custom/Espo/Custom/Classes/Select/Common/Where/CustomProperty.php');
$navigation = $read('espocrm/client/custom/tenant-workspace.js');
$template = $read('espocrm/client/custom/res/templates/customization/admin.tpl');
$visibility = $read('espocrm/client/custom/property-visibility.js');
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$account = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Account.json'), true, flags: JSON_THROW_ON_ERROR);

$tables = ['nexa_custom_entity_definition','nexa_custom_field_definition','nexa_custom_layout_definition','nexa_custom_record','nexa_custom_field_value','nexa_custom_relationship_definition','nexa_custom_relationship_link'];
foreach ($tables as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS {$table}"), "Migration is missing {$table}.");
}
$assert(substr_count($migration, 'tenant_id CHAR(36) NOT NULL') === count($tables), 'Every customization table must require tenant_id.');
$assert(substr_count($migration, 'service_id CHAR(36) NOT NULL') === count($tables), 'Every customization table must require service_id.');
$assert(str_contains($service, 'tenantContextStore->require()'), 'Customization operations must require trusted tenant context.');
$assert(str_contains($service, 'Only a tenant administrator can manage customization.'), 'Definition mutation must be tenant-admin-only.');
$assert(str_contains($service, 'acl->checkEntityRead'), 'Native custom-property reads must retain record ACL.');
$assert(str_contains($service, 'acl->checkEntityEdit'), 'Native custom-property writes must retain record ACL.');
$assert(str_contains($service, 'is_unique'), 'Tenant custom fields must enforce configured uniqueness.');
$assert(str_contains($service, 'standardFields'), 'Property administration must include standard and tenant-created properties in one catalogue.');
$assert(str_contains($service, 'SYSTEM_OBJECTS'), 'Customization must expose a curated business-object registry rather than the full framework entity list.');
$assert(str_contains($service, "'systemObjects' =>"), 'The definition API must return business-object availability metadata.');
foreach (['Contact','Account','Lead','Opportunity','Case','Campaign','TargetList','Task','Meeting','Call','Document','KnowledgeBaseArticle'] as $businessObject) {
    $assert(str_contains($service, "'{$businessObject}' =>"), "The business-object catalogue is missing {$businessObject}.");
}
foreach (['AuthToken','AppLogRecord','EmailQueueItem','PasswordChangeRequest','WebhookQueueItem'] as $internalObject) {
    $assert(!str_contains($service, "'{$internalObject}' =>"), "Platform-internal object {$internalObject} must not appear in tenant customization.");
}
$assert(str_contains($preferenceMigration, 'CREATE TABLE IF NOT EXISTS nexa_property_preference'), 'Tenant property preferences need a scoped persistence table.');
$assert(str_contains($service, "'propertyPreference' => \$this->savePropertyPreference"), 'The definition API must persist property visibility changes.');
$assert(str_contains($service, 'This core property is required and cannot be disabled.'), 'Required core properties must remain enabled.');
$assert(str_contains($service, "customization.property.visibility.updated"), 'Property visibility changes must be audited.');
$assert(str_contains($service, 'throw new Conflict'), 'Duplicate custom and standard property identities must be rejected explicitly.');
$assert(str_contains($service, "'layouts'=>\$definitionSet['layouts']"), 'Existing records must receive saved tenant layouts.');
$assert(str_contains($service, 'enforceCardinality'), 'Custom links must enforce their declared relationship cardinality.');
$assert(str_contains($service, 'relationshipWorkspace'), 'Custom relationships must expose a tenant-scoped record-linking workspace.');
$assert(str_contains($service, 'candidateOptions'), 'Custom relationships must provide ACL-scoped candidate discovery.');
$assert(str_contains($service, 'customization.relationship.unlinked'), 'Custom relationship removal must be audited.');
$assert(str_contains($service, 'ROLLBACK TO SAVEPOINT'), 'Failed custom value and record writes must roll back atomically.');
$assert(str_contains($helper, 'Nexa/customization/values/'), 'Contact and Account forms must persist tenant custom values through the API.');
$assert(($account['recordViews']['edit'] ?? null) === 'custom:views/account/record/edit', 'Account edits must mount tenant custom properties.');
foreach (['Objects &amp; properties','Properties','Record layout','Associations','Object settings','Create custom object'] as $label) $assert(str_contains($template, $label), "Visual administration is missing {$label}.");
$assert(substr_count($template, 'role="tabpanel"') === 5, 'Every object workspace tab must expose an accessible tab panel.');
$assert(substr_count($template, 'aria-controls=') === 5, 'Every object workspace tab must identify its controlled panel.');
$assert(!str_contains($template, 'Related administration'), 'Objects and Properties must not duplicate the main Administration catalogue.');
$assert(str_contains($template, 'Advanced settings'), 'Technical internal names must be hidden behind advanced settings.');
$assert(str_contains($admin, 'keyFrom('), 'The visual builder must generate internal names from business labels.');
$assert(str_contains($admin, 'addFieldToLayout'), 'Property creation must support immediate screen placement.');
$assert(str_contains($admin, 'propertyCatalogueFor'), 'The visual builder must display a combined standard and custom property catalogue.');
$assert(str_contains($admin, 'toggleProperty'), 'The property catalogue must expose tenant enable and disable controls.');
$assert(str_contains($admin, 'customizationEnabled === true'), 'Planned system objects must not expose unfinished customization controls.');
$assert(str_contains($admin, 'renderObjectSettings'), 'Each object must expose clear ownership and availability settings.');
$assert(!str_contains($template, 'Delivery module'), 'Tenant-facing object settings must not expose internal roadmap module terminology.');
$assert(str_contains($read('espocrm/client/custom/css/customization.css'), '.nexa-object-card[hidden]'), 'Object live search must visually hide non-matching grid cards.');
$assert(str_contains($admin, "views/admin/entity-manager/modals/select-icon"), 'Custom-object creation must reuse the native searchable icon selector.');
$assert(str_contains($template, 'data-action="select-object-icon"'), 'Custom-object creation must expose the native icon selector control.');
$assert(str_contains($template, 'How customization works') && strpos($template, 'How customization works') < strpos($template, 'Choose an object'), 'The setup guide must appear before object selection.');
$assert(str_contains($visibility, 'nexa-property-hidden-by-tenant'), 'Native Contact and Account screens must apply tenant property visibility.');
$assert(str_contains($visibility, "classList.contains('nexa-shell-ready')"), 'Property visibility must wait until the authenticated API client is configured.');
$assert(str_contains($runtime, 'field.is_enabled !== false'), 'Custom-object screens must omit disabled properties.');
$assert(str_contains($admin, 'isFilterable'), 'Searchability and filterability must be configured independently.');
$assert(str_contains($template, 'Include in keyword and global search'), 'The property builder must explain the searchable rule precisely.');
$assert(str_contains($template, 'Allow as a list filter'), 'The property builder must expose the list-filter rule.');
$assert(str_contains($propertyFilter, "type: 'nexaCustomProperty'"), 'Contact and Account advanced filters must submit the tenant custom-property condition.');
$assert(str_contains($textFilter, 'is_searchable=1'), 'Native keyword and global searches must restrict custom values to searchable definitions.');
$assert(str_contains($whereFilter, 'is_filterable=1'), 'Advanced list filtering must reject properties that are not filterable.');
$assert(str_contains($admin, 'Nexa/customization/definitions'), 'Visual administration must use the authenticated definition API.');
$assert(str_contains($admin, 'Nexa/customization/entities/'), 'Visual administration must manage custom-object records.');
$assert(str_contains($admin, 'manage-record-associations'), 'Custom records must expose association management.');
$assert(str_contains($admin, 'data-association-search'), 'Association selection must provide live record search.');
$assert(str_contains($template, 'data-dialog="record-associations"'), 'Association management must use an accessible modal dialog.');
$assert(str_contains($navigation, 'loadTenantCustomObjectNavigation'), 'Active custom objects must be added to normal CRM navigation.');
$assert(str_contains($navigation, '#NexaObject/index/entity='), 'Custom-object navigation must open the tenant record workspace.');
$assert(str_contains($runtime, 'Nexa/customization/entities/'), 'Normal users need a custom-object record workspace.');
$assert(str_contains($runtime, 'data-connect-candidate'), 'The record workspace must support configured associations.');
$assert(str_contains($runtime, "field.is_required && !fields.some"), 'Create and edit layouts must restore required properties omitted by tenant layout configuration.');
$assert(str_contains($runtimeTemplate, 'data-runtime-screen="list"'), 'The record workspace must expose a list state.');
$assert(str_contains($runtimeTemplate, 'data-runtime-screen="form"'), 'The record workspace must expose create and edit states.');
$assert(str_contains($runtimeTemplate, 'data-runtime-screen="detail"'), 'The record workspace must expose a detail state.');

$expectedRoutes = [
    '/Nexa/customization/definitions', '/Nexa/customization/definitions/:kind',
    '/Nexa/customization/values/:entityType/:id', '/Nexa/customization/entities/:entityKey/records',
    '/Nexa/customization/entities/:entityKey/records/:id',
    '/Nexa/customization/relationships/link',
    '/Nexa/customization/relationships/:definitionId/:entityType/:entityId',
    '/Nexa/customization/relationships/link/:id',
];
foreach ($expectedRoutes as $route) {
    $matches = array_filter($routes, static fn(array $item): bool => ($item['route'] ?? '') === $route);
    $assert($matches !== [], "Authenticated customization route is missing: {$route}.");
    foreach ($matches as $match) $assert(empty($match['noAuth']), "Customization route must not be public: {$route}.");
}
foreach (['tenant-a','tenant-b','isolation-alpha','isolation-beta'] as $literal) {
    $assert(!str_contains($service . $helper . $admin, $literal), "Customization must not hardcode {$literal}.");
}

echo "Tenant customization contracts passed.\n";
