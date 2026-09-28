<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$migration = $read('database/shared/migrations/0051_add_segment_governance.sql');
$builderMigration = $read('database/shared/migrations/0052_expand_segment_builder.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$service = $read('espocrm/custom/Espo/Custom/Tools/Segment/SegmentWorkspaceService.php');
$template = $read('espocrm/client/custom/res/templates/segment/workspace.tpl');
$view = $read('espocrm/client/custom/src/views/segment/workspace.js');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$job = $read('espocrm/custom/Espo/Custom/Jobs/RecalculateDynamicSegments.php');
$scheduledJob = $read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/ScheduledJob.json');

foreach (['nexa_segment_definition', 'nexa_segment_version', 'nexa_segment_run', 'nexa_segment_membership_event'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Segment migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1 && ($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be registered as service owned.");
}
foreach (['/Nexa/segments/workspace', '/Nexa/segments/preview', '/Nexa/segments/:id/recalculate', '/Nexa/segments/:id/archive'] as $route) {
    $assert(str_contains($routes, $route), "Segment API route {$route} is missing.");
}
$assert(str_contains($service, "->get('TargetList')->create"), 'Segments must create the native Target List record.');
$assert(str_contains($service, 'contact_target_list'), 'Segments must retain native Contact-to-Target-List membership.');
$assert(str_contains($service, 'tenant_id=?') && str_contains($service, 'service_id=?'), 'Segment queries must enforce tenant and service scope.');
$assert(str_contains($service, 'nexa_consent_state') && str_contains($service, "marketing_status='Marketing'"), 'Audience eligibility must use governed consent and marketing status.');
$assert(str_contains($service, 'nexa_segment_membership_event') && str_contains($service, "'entered'") && str_contains($service, "'exited'"), 'Dynamic membership changes must be explainable and auditable.');
$assert(str_contains($service, 'TYPE_OPERATORS') && str_contains($view, 'selectedField?.operators'), 'Segment conditions must be compatible with their selected property type.');
$assert(str_contains($job, 'recalculateAll') && str_contains($scheduledJob, 'RecalculateDynamicSegments'), 'Dynamic segments must be recalculated automatically for each tenant.');
$assert(str_contains($template, 'Static segment') && str_contains($template, 'Active segment'), 'The workspace must explain both membership models in business language.');
$assert(str_contains($template, 'data-segment-search') && str_contains($view, 'renderSegments'), 'Lists and segments must support live search.');
$assert(str_contains($template, 'data-segment-rules') && str_contains($view, 'previewSegment'), 'Dynamic segments must provide a visual rule builder and preview.');
$assert(str_contains($template, 'data-builder-step="1"') && str_contains($template, 'data-builder-step="3"'), 'Segment creation must use a guided business workflow.');
$assert(str_contains($template, 'data-exclusion-rules') && str_contains($service, 'exclusion_rules_json'), 'The builder must support governed audience exclusions.');
$assert(str_contains($builderMigration, 'snapshot_from_rules') && str_contains($service, 'snapshotFromRules'), 'Static segments must support a one-time filtered snapshot.');
$assert(str_contains($routes, '/Nexa/segments/:id/duplicate') && str_contains($routes, '/Nexa/segments/:id/export'), 'Segments must support cloning and governed exports.');
$assert(str_contains($registry, "'#NexaSegments'"), 'Lists & Segments must be an active Marketing navigation surface.');
$assert(substr_count($registry, "'nexa-lists-segments'") === 1, 'The product navigation must expose one Lists & Segments entry.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Segment service must not hard-code demo tenants.');

echo "Lists and segments workspace contracts passed.\n";
