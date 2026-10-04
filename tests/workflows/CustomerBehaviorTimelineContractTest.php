<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$migration = $read('database/shared/migrations/0060_scope_timeline_and_backfill_identity.sql');
$service = $read('espocrm/custom/Espo/Custom/Tools/Event/BehaviorEventService.php');
$query = $read('espocrm/custom/Espo/Custom/Tools/Customer/CustomerFoundationQueryService.php');
$workspace = $read('espocrm/client/custom/src/views/contact/record/detail-workspace.js');
$ownership = $read('database/shared/table-ownership-manifest.json');

foreach ([
    'ADD COLUMN `service_id`',
    'fk_nexa_timeline_tenant_service',
    'tenant_id`,`service_id`,`source_entity_type',
] as $needle) {
    $assert(str_contains($migration, $needle), "Timeline migration is missing {$needle}.");
}

foreach ([
    'visitorContact',
    'contactAccount',
    'backfillVisitorEvents',
    'updateLastWebsiteVisit',
    'INSERT IGNORE INTO nexa_timeline_event',
    'tenant_id = ? AND service_id = ?',
] as $needle) {
    $assert(str_contains($service, $needle), "Behavior identity backfill is missing {$needle}.");
}

foreach ([
    't.service_id = ?',
    'LEFT JOIN nexa_behavior_event',
    "source_entity_type'] === 'BehaviorEvent'",
    "\$row['behavior'] =",
] as $needle) {
    $assert(str_contains($query, $needle), "Customer timeline query is missing {$needle}.");
}

foreach ([
    'loadContactBehaviorTimeline',
    'Nexa/customer/Contact/',
    'contactBehaviorActivities',
    'behaviorDetails',
    "'page.viewed': 'Page viewed'",
    "'form.submitted': 'Form submitted'",
] as $needle) {
    $assert(str_contains($workspace, $needle), "Contact behavior timeline UI is missing {$needle}.");
}

$manifest = json_decode($ownership, true, flags: JSON_THROW_ON_ERROR);
$timeline = array_values(array_filter(
    $manifest['tables'] ?? [],
    static fn (array $table): bool => ($table['name'] ?? '') === 'nexa_timeline_event',
));
$assert(count($timeline) === 1, 'Timeline ownership classification is missing or duplicated.');
$assert(($timeline[0]['classification'] ?? '') === 'serviceOwned', 'Timeline must be service owned.');
$assert(($timeline[0]['serviceScope'] ?? '') === 'required', 'Timeline must require service scope.');

echo "Customer behavior timeline contracts passed.\n";
