<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('database/shared/migrations/0047_add_form_governance.sql');
$runtimeMigration = $read('database/shared/migrations/0048_add_form_runtime_events.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$service = $read('espocrm/custom/Espo/Custom/Tools/Form/FormWorkspaceService.php');
$template = $read('espocrm/client/custom/res/templates/form/workspace.tpl');
$view = $read('espocrm/client/custom/src/views/form/workspace.js');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$resolver = $read('espocrm/application/Espo/Core/Tenant/TenantResolver.php');
$runtime = $read('espocrm/custom/Espo/Custom/Tools/Form/PublicFormRuntimeService.php');
$capture = $read('espocrm/application/Espo/Tools/LeadCapture/CaptureService.php');
$entryPoint = $read('espocrm/application/Espo/EntryPoints/LeadCaptureForm.php');
$publicView = $read('espocrm/client/custom/src/views/form/public-form.js');
$publicCss = $read('espocrm/client/custom/css/public-form.css');
$clientMetadata = $read('espocrm/custom/Espo/Custom/Resources/metadata/app/client.json');
$tableHelper = $read('espocrm/client/custom/src/workspace-table.js');

foreach (['nexa_form_profile', 'nexa_form_version'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Forms migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1, "Ownership manifest is missing {$table}.");
    $assert(($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be service owned.");
}
$assert(str_contains($runtimeMigration, 'CREATE TABLE IF NOT EXISTS `nexa_form_event`'), 'Forms runtime migration is missing its event ledger.');
$eventEntry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === 'nexa_form_event'));
$assert(count($eventEntry) === 1 && ($eventEntry[0]['classification'] ?? null) === 'serviceOwned', 'Form events must be service owned.');
foreach (['draft_configuration_json', 'configuration_hash', 'version_number', 'has_unpublished_changes'] as $column) {
    $assert(str_contains($migration, "`{$column}`"), "Forms governance schema is missing {$column}.");
}
foreach (['/Nexa/forms/workspace', '/Nexa/forms/:id/publish', '/Nexa/forms/:id/archive', '/Nexa/forms/submissions', '/Nexa/forms/submissions/:id'] as $route) {
    $assert(str_contains($routes, $route), "Forms API route {$route} is missing.");
}
$assert(str_contains($service, "->get('LeadCapture')"), 'Forms must retain the native Lead Capture persistence service.');
$assert(str_contains($service, 'lead_capture_log_record'), 'Forms workspace must surface native submission history.');
$assert(str_contains($service, "'formTheme'") && str_contains($template, 'name="formTheme"'), 'Forms must expose the native Lead Capture theme setting.');
$assert(str_contains($resolver, 'resolveLeadCaptureFormId'), 'Public Lead Capture requests must resolve their owning tenant from the opaque form ID.');
$assert(str_contains($runtime, 'nexaConsentAccepted') && str_contains($runtime, 'validateSubmission') && str_contains($runtime, 'nexa_form_event'), 'Public Forms must enforce consent and record governed runtime evidence.');
$assert(str_contains($capture, 'nexaFormRuntime->validateSubmission') && str_contains($capture, "'data' => \$data"), 'Native Lead Capture must validate and expose the governed submission payload.');
$assert(str_contains($entryPoint, 'custom:controllers/nexa-lead-capture-form') && str_contains($publicView, 'nexaVisitorId'), 'Public Forms must collect first-party attribution through the native form runtime.');
$assert(str_contains($publicView, "classList.add('nexa-public-form')"), 'Public Forms must expose a scoped styling boundary.');
$assert(str_contains($publicCss, 'color: #172f35 !important') && str_contains($publicCss, '-webkit-text-fill-color: #172f35 !important'), 'Public Form inputs must retain readable typed text across themes and browser autofill.');
$assert(str_contains($clientMetadata, 'client/custom/css/public-form.css'), 'Public Form readability styles must be registered in client metadata.');
$assert(str_contains($runtime, 'nexaRedirectDelaySeconds') && str_contains($template, 'name="redirectDelaySeconds"'), 'Forms must expose a governed redirect delay.');
$assert(str_contains($template, 'data-conditional-rules') && str_contains($view, 'renderConditionalRules') && str_contains($runtime, 'applyConditionalValidation'), 'Forms must configure, render and enforce conditional fields.');
$assert(str_contains($template, 'name="progressiveProfiling"') && str_contains($publicView, 'rememberCompletedFields') && str_contains($publicView, 'completedFields'), 'Forms must progressively hide completed optional properties for returning visitors.');
$assert(str_contains($view, 'data-action="change-mapping"') && str_contains($service, 'fieldMapping(') && str_contains($runtime, 'applyRecordActions'), 'Forms must validate and apply explicit CRM field mappings.');
$assert(str_contains($template, 'name="assignedUserId"') && str_contains($template, 'name="lifecycleStage"') && str_contains($template, 'name="marketingStatus"'), 'Forms must expose governed ownership, lifecycle and marketing actions.');
$assert(str_contains($runtime, "if (!\$this->insertEvent") && str_contains($runtime, 'rowCount() > 0'), 'Post-submission actions must be idempotent per governed submission event.');
$assert(str_contains($publicView, 'await this.reRender()') && str_contains($publicView, 'redirectAfterSuccess') && str_contains($publicView, 'setTimeout(resolve, 1000)') && str_contains($publicView, 'document.location.href = url'), 'Public Forms must render the success state and countdown before redirecting.');
$assert(str_contains($service, 'viewCount') && str_contains($service, 'conversionRate'), 'Forms workspace must report views and conversion performance.');
$assert(str_contains($service, 'tenant_id=?') && str_contains($service, 'service_id=?'), 'Forms queries must enforce tenant and service scope.');
$assert(str_contains($template, 'data-form-editor') && str_contains($template, 'data-submission-list'), 'Forms workspace must include the builder and submission history.');
$assert(str_contains($template, 'data-workspace-table="forms"') && str_contains($template, 'data-workspace-table="submissions"'), 'Forms and submissions must use the standard workspace table behavior.');
$assert(str_contains($view, 'fetchSubmissionPage') && str_contains($view, 'loadMoreSubmissions') && str_contains($view, 'data-table-sort'), 'Submission history must support incremental loading and sorting without pagination controls.');
$assert(str_contains($tableHelper, "th.draggable = true") && str_contains($tableHelper, 'nexa-col-resizer') && str_contains($tableHelper, 'state.order') && str_contains($tableHelper, 'state.widths'), 'Workspace tables must support persistent column moving and resizing.');
$assert(str_contains($service, "target_type'] === 'Lead'") && str_contains($service, "submission['is_created']") && str_contains($service, "->get('Lead')->delete") && str_contains($service, 'DeleteParams::create()'), 'Deleting a created Lead submission must use the native soft-delete service without deleting matched records.');
$assert(str_contains($view, 'data-action="delete-submission"') && str_contains($template, 'data-submission-delete-dialog'), 'Submission history must provide a confirmed temporary-delete flow.');
$assert(str_contains($view, 'renderFieldCatalog') && str_contains($view, 'publishForm') && str_contains($view, 'copyEmbed'), 'Forms client must support fields, publishing and embedding.');
$assert(str_contains($registry, "'#NexaForms'"), 'Forms must be an active Marketing navigation surface.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Forms service must not hard-code demo tenants.');

echo "Forms workspace contracts passed.\n";
