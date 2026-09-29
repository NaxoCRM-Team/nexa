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
$migrationReplay = $read('tests/development/Phase4MigrationReplayTest.ps1');
$securityContract = $read('tests/workflows/Phase4SecurityContractTest.php');
$exitGate = $read('docs/development/phase-4-exit-gate.md');

foreach (['M08', 'M09', 'Phase 4 - Consent, Content, Segmentation and Campaign Foundation'] as $marker) {
    $assert(str_contains($roadmap, $marker), "Phase 4 roadmap marker is missing: {$marker}.");
}
foreach (['Nexa/consent', 'Nexa/forms', 'Nexa/assets', 'Nexa/landing-pages', 'Nexa/segments', 'Nexa/campaigns', 'Nexa/events'] as $route) {
    $assert(str_contains($routes, $route), "Phase 4 API route is missing: {$route}.");
}
foreach (['nexa_consent_event', 'nexa_cookie_receipt', 'nexa_form_profile', 'nexa_asset_profile', 'nexa_landing_page', 'nexa_segment_definition', 'nexa_campaign_profile', 'nexa_campaign_enrollment', 'nexa_campaign_event', 'nexa_behavior_event', 'nexa_public_rate_limit'] as $table) {
    $assert(str_contains($manifest, $table), "Phase 4 ownership manifest is missing {$table}.");
}
$assert(str_contains($migrationReplay, '0043_add_consent_governance.sql') && str_contains($migrationReplay, "'nexa_campaign_enrollment'") && str_contains($migrationReplay, '$phase4Migrations'), 'Phase 4 incremental replay range is incomplete.');
$assert(str_contains($securityContract, 'signed') && str_contains($securityContract, 'rate'), 'Phase 4 public security acceptance is incomplete.');
$assert(str_contains($exitGate, 'Campaign definitions, consent-aware previews and governed enrollment'), 'The Phase 4 gate must record campaign acceptance evidence.');

echo "Phase 4 acceptance contracts passed.\n";
