<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$navbar = $read('espocrm/client/custom/tenant-workspace.js');
$provisioner = $read('espocrm/bin/provision-demo-tenants.php');
$slaService = $read('espocrm/custom/Espo/Custom/Tools/CaseService/CaseSlaService.php');
$migrationRunner = $read('scripts/dev/apply-shared-schema.ps1');
$replay = $read('tests/development/Phase3MigrationReplayTest.ps1');

$assert(str_contains($registry, "['Case', 'Cases', '#Case', 'fas fa-headset']"), 'Cases must be an explicit active Service navigation destination.');
$assert(str_contains($navbar, 'const existing = existingByName.get(item[0])') && str_contains($navbar, 'usedNames.add(item[0])'), 'Explicit native destinations must reuse existing nav definitions without duplicates.');
foreach (['portal_portal_role', 'portal_user', 'portal_role_user', 'account_portal_user', "'cases' => ["] as $marker) {
    $assert(str_contains($provisioner, $marker), "Demo provisioner is missing {$marker}.");
}
$assert(str_contains($provisioner, "'is_internal' => \$case['internal'] ? 1 : 0"), 'Demo Cases must include a Portal-hidden internal record.');
$assert(str_contains($slaService, 'ensureDefaultPolicies') && str_contains($slaService, 'DEFAULT_POLICIES'), 'New tenants must receive SLA defaults after migration-time seeding.');
$assert(str_contains($migrationRunner, 'Applied migration') && str_contains($migrationRunner, 'create a new numbered migration'), 'Applied migration checksums must be immutable.');
$assert(str_contains($replay, 'nexa_phase3_clean_test') && str_contains($replay, 'nexa_phase3_upgrade_test'), 'Phase 3 clean and upgrade replay databases are missing.');

echo "Phase 3 acceptance contracts passed.\n";
