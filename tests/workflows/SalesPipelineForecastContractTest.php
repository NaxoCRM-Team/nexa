<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) throw new RuntimeException("Missing sales contract: {$path}");
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('database/shared/migrations/0035_add_sales_pipeline_forecasting.sql');
$quoteMigration = $read('database/shared/migrations/0036_add_quote_line_item_snapshots.sql');
$lifecycleMigration = $read('database/shared/migrations/0037_complete_sales_lifecycle.sql');
$taxMigration = $read('database/shared/migrations/0038_add_quote_tax_snapshot.sql');
$service = $read('espocrm/custom/Espo/Custom/Tools/Sales/SalesWorkspaceService.php');
$hook = $read('espocrm/custom/Espo/Custom/Hooks/Opportunity/SalesLifecycle.php');
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$entity = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json'), true, flags: JSON_THROW_ON_ERROR);
$client = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Opportunity.json'), true, flags: JSON_THROW_ON_ERROR);
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$list = $read('espocrm/client/custom/src/views/opportunity/list-v2.js');
$stageField = $read('espocrm/client/custom/src/views/opportunity/fields/stage.js');
$search = $read('espocrm/client/custom/src/views/opportunity/record/search-live.js');
$detail = $read('espocrm/client/custom/src/views/opportunity/record/detail-workspace.js');
$sales = $read('espocrm/client/custom/src/views/sales/workspace.js');
$styles = $read('espocrm/client/custom/css/sales-workspace.css');

foreach (['pipeline_id', 'forecast_category', 'expected_revenue', 'next_step', 'loss_reason'] as $column) {
    $assert(str_contains($migration, "`{$column}`"), "Opportunity migration is missing {$column}.");
}
foreach (['nexa_sales_pipeline', 'nexa_sales_stage', 'nexa_product', 'nexa_opportunity_line_item', 'nexa_quote', 'nexa_opportunity_revenue_history'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Sales migration is missing {$table}.");
}
$assert(str_contains($quoteMigration, 'CREATE TABLE IF NOT EXISTS `nexa_quote_line_item`'), 'Quote snapshots need tenant-owned line items.');
$assert(str_contains($lifecycleMigration, 'nexa_quote_status_history'), 'Quote lifecycle needs append-only status history.');
$assert(str_contains($taxMigration, '`tax_percent`'), 'Quote line snapshots must preserve tax.');
foreach (['pipelineId', 'forecastCategory', 'expectedRevenue', 'nextStep', 'lossReason'] as $field) {
    $assert(isset($entity['fields'][$field]), "Opportunity metadata is missing {$field}.");
}
$routeMap = [];
foreach ($routes as $route) $routeMap[$route['method'] . ' ' . $route['route']] = $route['actionClassName'];
foreach (['get /Nexa/sales/workspace', 'get /Nexa/sales/opportunity/:id', 'post /Nexa/sales/pipelines', 'post /Nexa/sales/pipelines/:id', 'delete /Nexa/sales/pipelines/:id', 'post /Nexa/sales/pipelines/order', 'post /Nexa/sales/pipelines/:pipelineId/stages', 'post /Nexa/sales/pipelines/:pipelineId/stages/order', 'post /Nexa/sales/products', 'post /Nexa/sales/products/:id', 'delete /Nexa/sales/products/:id', 'post /Nexa/sales/stages/:id', 'delete /Nexa/sales/stages/:id', 'post /Nexa/sales/opportunity/:id/line-items', 'post /Nexa/sales/opportunity/:id/line-items/:lineId', 'delete /Nexa/sales/opportunity/:id/line-items/:lineId', 'post /Nexa/sales/opportunity/:id/quotes', 'post /Nexa/sales/quotes/:id/status'] as $route) {
    $assert(isset($routeMap[$route]), "Sales API route is missing: {$route}.");
}
$assert(str_contains($service, 'tenantContextStore->require()'), 'Sales APIs must require trusted tenant context.');
$assert(str_contains($service, "tenant_id = ? AND service_id = ?"), 'Sales reads must bind tenant and service scope.');
$assert(str_contains($service, "acl->checkReadAll('Opportunity')"), 'Forecast aggregation must preserve Opportunity row permissions.');
$assert(str_contains($service, 'currencyConverter->convert') && str_contains($service, 'baseAmount'), 'Forecast aggregation must convert mixed currencies to the configured base currency.');
$assert(str_contains($service, 'editableOpportunity') && str_contains($service, 'recalculateOpportunity'), 'Commercial writes must enforce Opportunity ACL and recalculate through the ORM.');
$assert(str_contains($service, 'nexa_quote_line_item'), 'Quote creation must preserve line-item snapshots.');
$assert(str_contains($service, 'QUOTE_TRANSITIONS') && str_contains($service, 'recordQuoteStatus'), 'Quote statuses need validated transitions and append-only history.');
$assert(str_contains($service, 'reorderPipelines') && str_contains($service, 'reorderStages'), 'Pipeline and stage ordering must be persisted.');
$assert(str_contains($service, 'updateProduct') && str_contains($service, 'archiveProduct'), 'Products need complete edit and archive lifecycles.');
$assert(str_contains($service, 'updateLineItem') && str_contains($service, 'removeLineItem'), 'Opportunity line items need edit and removal operations.');
$assert(str_contains($service, 'assigned_user_id = ?') && str_contains($service, 'entity_team'), 'Forecasts must support native owner and team filters.');
$assert(str_contains($hook, 'prepareOpportunity') && str_contains($hook, 'recordOpportunity'), 'Every Opportunity write path needs stage rules and revenue history.');
$assert(($client['views']['list'] ?? null) === 'custom:views/opportunity/list-v2', 'Opportunity lists must use the Nexa workspace.');
$assert(str_contains($list, 'options.pagination = false'), 'Opportunity lists must use incremental scrolling.');
$assert(str_contains($list, "!create.classList.contains('nexa-opportunity-create-button')"), 'Opportunity control decoration must not trigger an observer loop.');
$assert(str_contains($stageField, "if (this.mode === 'edit')"), 'Opportunity list rows must not load the complete sales workspace to display native stages.');
$assert(str_contains($search, "addHandler('input'"), 'Opportunity search must run as the user types.');
$assert(str_contains($detail, 'data-nexa-opportunity-workspace'), 'Opportunity details must use the Nexa sales workspace.');
$assert(str_contains($detail, 'data-line-item-form') && str_contains($detail, 'data-quote-form'), 'Opportunity details must operate line items and quotes.');
$assert(str_contains($sales, 'forecastSummary') && str_contains($sales, 'stageSummary'), 'Sales workspace must render pipeline and forecast summaries.');
$assert(str_contains($sales, 'data-stage-form') && str_contains($sales, 'selectedPipelineId'), 'Pipeline administration must support selection and stage rules.');
$assert(str_contains($sales, 'archivePipeline') && str_contains($sales, 'archiveStage') && str_contains($sales, 'saveProduct'), 'Sales administration must expose complete lifecycle controls.');
$assert(str_contains($registry, "'#NexaSales/pipelines'") && str_contains($registry, "'#NexaSales/forecasts'"), 'Sales navigation must link to active workspaces.');
$assert(str_contains($styles, '@media (max-width: 575px)'), 'Sales workflows need a mobile layout contract.');

echo "Sales pipeline and forecast contracts passed.\n";
