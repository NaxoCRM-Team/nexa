<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) throw new RuntimeException("Missing Lead contract: {$path}");
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('database/shared/migrations/0034_extend_lead_lifecycle_conversion.sql');
$controller = $read('espocrm/custom/Espo/Custom/Controllers/Lead.php');
$service = $read('espocrm/custom/Espo/Custom/Tools/Lead/LeadConversionService.php');
$customization = $read('espocrm/custom/Espo/Custom/Tools/Customization/CustomizationService.php');
$visibility = $read('espocrm/client/custom/property-visibility.js');
$entity = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Lead.json'), true, flags: JSON_THROW_ON_ERROR);
$client = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Lead.json'), true, flags: JSON_THROW_ON_ERROR);
$detail = json_decode($read('espocrm/custom/Espo/Custom/Resources/layouts/Lead/detail.json'), true, flags: JSON_THROW_ON_ERROR);
$list = json_decode($read('espocrm/custom/Espo/Custom/Resources/layouts/Lead/list.json'), true, flags: JSON_THROW_ON_ERROR);
$listView = $read('espocrm/client/custom/src/views/lead/list-v2.js');
$recordList = $read('espocrm/client/custom/src/views/lead/record/list-infinite.js');
$liveSearch = $read('espocrm/client/custom/src/views/lead/record/search-live.js');
$recordDetail = $read('espocrm/client/custom/src/views/lead/record/detail.js');
$nameField = $read('espocrm/client/custom/src/views/lead/fields/name.js');
$nameTemplate = $read('espocrm/client/custom/res/templates/lead/fields/name/list-link.tpl');
$styles = $read('espocrm/client/custom/css/crm-workflows.css');
$selectDefs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/selectDefs/Lead.json'), true, flags: JSON_THROW_ON_ERROR);

foreach (['rating','lead_score','lifecycle_stage','marketing_status','legal_basis','converted_by_id'] as $column) {
    $assert(str_contains($migration, "`{$column}`"), "Lead migration is missing {$column}.");
}
$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `nexa_lead_conversion`'), 'The immutable Lead conversion ledger is missing.');
$assert(str_contains($controller, 'parent::postActionConvert'), 'The Nexa controller must retain the native conversion implementation.');
$assert(str_contains($service, 'tenantContextStore->require()'), 'Lead conversion must require trusted tenant context.');
$assert(str_contains($service, 'FOR UPDATE'), 'Lead conversion must lock the tenant-owned source record.');
$assert(str_contains($service, 'ROLLBACK TO SAVEPOINT'), 'A failed Lead conversion must roll back atomically.');
foreach (['copyCustomValues','copyTargetLists','moveCampaignHistory','copyGovernedRelationships'] as $method) {
    $assert(str_contains($service, $method), "Conversion preservation is missing {$method}.");
}
foreach (['lead.converted','nexa_audit_event','nexa_outbox_event','nexa_timeline_event'] as $contract) {
    $assert(str_contains($service, $contract), "Conversion governance is missing {$contract}.");
}
foreach (['rating','leadScore','lifecycleStage','marketingStatus','legalBasis','convertedBy'] as $field) {
    $assert(isset($entity['fields'][$field]), "Lead metadata is missing {$field}.");
}
$assert(str_contains($customization, "['Contact', 'Account', 'Lead']"), 'Tenant custom properties must support Leads.');
$assert(str_contains($visibility, "['Contact', 'Account', 'Lead']"), 'Tenant property visibility must support Lead screens.');
$assert(($client['recordViews']['detail'] ?? null) === 'custom:views/lead/record/detail', 'Lead details must use the Nexa view.');
$assert(count($detail) >= 4, 'Lead details need profile, qualification, engagement and ownership sections.');
$assert(count($list) >= 8, 'Lead lists need the qualification and ownership columns.');
$assert(str_contains($listView, 'options.pagination = false'), 'Lead lists must replace page controls with incremental scrolling.');
$assert(str_contains($listView, 'My Leads') && str_contains($listView, 'All Leads'), 'Lead lists need user and tenant scope tabs.');
$assert(str_contains($recordList, 'nexa-lead-scroll-list'), 'Lead records need a stable scrollable table viewport.');
$assert(str_contains($recordList, 'showMoreRecords'), 'Lead lists must load additional scoped records incrementally.');
$assert(!str_contains($recordList, "status: {type: 'dropdown'}"), 'Inline editing must not bypass governed Lead conversion status.');
$assert(str_contains($liveSearch, "addHandler('input'"), 'Lead keyword search must run as the user types.');
$assert(str_contains($liveSearch, 'searchManager.getWhere()'), 'Live search must preserve the central tenant, ACL and filter query.');
$assert(isset($selectDefs['primaryFilterClassNameMap']['createdByMe']), 'My Leads needs a server-enforced primary filter.');
$assert(($entity['fields']['name']['view'] ?? null) === 'custom:views/lead/fields/name', 'Lead names must use the Nexa identity renderer.');
$assert(str_contains($nameField, 'initials') && str_contains($nameTemplate, 'nexa-lead-list-avatar'), 'Lead list names need an accessible initials avatar.');
$assert(str_contains($recordDetail, 'data-nexa-lead-workspace'), 'Lead details must render the Nexa qualification workspace.');
$assert(str_contains($recordDetail, 'Communication eligibility'), 'Lead details must expose marketing eligibility without duplicating Contact consent management.');
$assert(str_contains($recordDetail, 'Records directly connected to this Lead'), 'Lead relationships need a clear business explanation.');
$assert(str_contains($recordDetail, "this.relationship('Contact'") && str_contains($recordDetail, "this.relationship('Campaign'"), 'Connected records must summarize converted CRM records and Campaigns.');
$assert(!str_contains($recordDetail, "querySelectorAll('.bottom [data-name], .bottom .panel')"), 'Lead relationship rendering must not dismantle native panels by moving nested controls.');
$assert(str_contains($recordDetail, 'Use the Convert action above'), 'The qualification workspace must guide users to the governed conversion action.');
foreach (['overview', 'activity', 'history', 'tasks'] as $tab) {
    $assert(str_contains($recordDetail, "this.tab('{$tab}'"), "Lead details are missing the {$tab} workspace tab.");
}
$assert(!str_contains($recordDetail, "this.tab('properties'"), 'Lead details must not duplicate displayed information in an All Properties tab.');
$assert(str_contains($recordDetail, 'data-nexa-lead-set-campaign') && str_contains($recordDetail, 'data-nexa-lead-add-target-list'), 'Lead relationships need clear Campaign and Target List actions.');
$assert(str_contains($recordDetail, "'views/modals/select-records'") && str_contains($recordDetail, "scope: 'Campaign'"), 'Campaign assignment must use the native searchable selector.');
$assert(str_contains($recordDetail, 'createButton: true'), 'Permitted users need to create a Campaign from an empty selector.');
$assert(str_contains($recordDetail, 'campaignId: campaign.id') && str_contains($recordDetail, 'campaignId: null'), 'Campaigns must support direct assignment and removal.');
$assert(str_contains($styles, '.nexa-lead-remove-campaign[hidden]'), 'Remove Campaign must remain visually hidden when no Campaign is connected.');
$assert(str_contains($recordDetail, '[data-action="selectRelated"]'), 'Target List assignment must reuse the native permission-aware relationship picker.');
$assert(str_contains($recordDetail, 'Move the real Espo controls and panels'), 'Lead details must document why native conversion controls are relocated.');
$assert(str_contains($styles, '.nexa-lead-grid') && str_contains($styles, '@media (max-width: 767px)'), 'Lead details need a responsive three-column workspace contract.');

echo "Lead lifecycle and conversion contracts passed.\n";
