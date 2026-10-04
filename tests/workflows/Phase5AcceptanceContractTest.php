<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$roadmap = $read('docs/product/module-build-roadmap.md');
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$manifest = $read('database/shared/table-ownership-manifest.json');
$eventContract = $read('espocrm/custom/Espo/Custom/Tools/Event/EventContract.php');
$migrationReplay = $read('tests/development/Phase5MigrationReplayTest.ps1');
$runner = $read('scripts/dev/verify-phase-5.ps1');
$exitGate = $read('docs/development/phase-5-exit-gate.md');

foreach (['M11', 'Phase 5 - Customer Timeline, Tracking and Event Foundation'] as $marker) {
    $assert(str_contains($roadmap, $marker), "Phase 5 roadmap marker is missing: {$marker}.");
}
foreach (['Nexa/events', 'Nexa/events/:id/replay', 'Nexa/tracking/sources', 'Nexa/tracking/retention', 'Nexa/tracking/retention/purge'] as $route) {
    $assert(str_contains($routes, $route), "Phase 5 API route is missing: {$route}.");
}
foreach (['nexa_visitor_identity', 'nexa_behavior_event', 'nexa_behavior_event_replay', 'nexa_tracking_source', 'nexa_event_retention_policy', 'nexa_timeline_event', 'nexa_outbox_event'] as $table) {
    $assert(str_contains($manifest, $table), "Phase 5 ownership manifest is missing {$table}.");
}
foreach (['page.viewed', 'landing_page.viewed', 'link.clicked', 'form.submitted', 'asset.downloaded', 'video.started', 'video.progressed', 'video.completed', 'webinar.registered', 'webinar.attended', 'purchase.completed', 'email.replied', 'custom.'] as $eventType) {
    $assert(str_contains($eventContract, $eventType), "Phase 5 event catalogue is missing {$eventType}.");
}
$assert(str_contains($migrationReplay, '0053_add_behavior_event_foundation.sql') && str_contains($migrationReplay, '0061_add_event_retention_governance.sql') && str_contains($migrationReplay, '$phase5Migrations'), 'Phase 5 migration replay range is incomplete.');
$assert(str_contains($runner, 'Phase5MigrationReplayTest.ps1') && str_contains($runner, 'live-tracking-retention.spec.js'), 'Phase 5 verification runner is incomplete.');
$assert(str_contains($exitGate, 'trusted customer-event platform') && str_contains($exitGate, 'Clean installation') && str_contains($exitGate, 'incremental'), 'Phase 5 exit-gate evidence is incomplete.');

echo "Phase 5 acceptance contracts passed.\n";
