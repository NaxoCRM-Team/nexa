<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$migration = $read('database/shared/migrations/0061_add_event_retention_governance.sql');
$service = $read('espocrm/custom/Espo/Custom/Tools/Event/EventRetentionService.php');
$job = $read('espocrm/custom/Espo/Custom/Jobs/PurgeBehaviorEvents.php');
$scheduled = $read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/ScheduledJob.json');
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$template = $read('espocrm/client/custom/res/templates/tracking/workspace.tpl');
$workspace = $read('espocrm/client/custom/src/views/tracking/workspace.js');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, flags: JSON_THROW_ON_ERROR);

foreach ([
    'nexa_event_retention_policy',
    'identified_retention_days',
    'anonymous_retention_days',
    'replay_retention_days',
    'legal_hold_reason',
    'idx_nexa_behavior_retention',
    'idx_nexa_behavior_replay_retention',
    'idx_nexa_visitor_retention',
] as $needle) {
    $assert(str_contains($migration, $needle), "Retention migration is missing {$needle}.");
}

foreach ([
    'PURGE_BATCH_SIZE = 5000',
    'eligibleEventIds',
    'orphanVisitorIds',
    'published_at IS NULL',
    "status IN ('queued','processing')",
    "source_entity_type='BehaviorEvent'",
    "aggregate_type='BehaviorEvent'",
    'event.retention.policy.updated',
    'event.retention.purge.completed',
    'tenant_id=? AND service_id=?',
] as $needle) {
    $assert(str_contains($service, $needle), "Retention service is missing {$needle}.");
}

foreach ([
    '/Nexa/tracking/retention',
    '/Nexa/tracking/retention/purge',
] as $route) {
    $assert(str_contains($routes, $route), "Retention route is missing {$route}.");
}

$assert(str_contains($job, 'purgeScheduled'), 'The retention job must use the governed service.');
$assert(str_contains($scheduled, 'PurgeBehaviorEvents'), 'The native scheduled-job catalogue is missing retention.');
foreach ([
    'Event retention',
    'Identified customer events',
    'Anonymous visitor events',
    'Place behavior data under legal hold',
    'Purge due data',
] as $label) {
    $assert(str_contains($template, $label), "Tracking retention UI is missing {$label}.");
}
foreach ([
    'saveRetention',
    'toggleRetentionHold',
    'purgeRetention',
    'Nexa/tracking/retention',
] as $needle) {
    $assert(str_contains($workspace, $needle), "Tracking retention interaction is missing {$needle}.");
}

$tables = array_column($manifest['tables'] ?? [], null, 'name');
$assert(
    ($tables['nexa_event_retention_policy']['classification'] ?? '') === 'serviceOwned',
    'Retention policy must be service owned.',
);
$assert(
    ($tables['nexa_event_retention_policy']['serviceScope'] ?? '') === 'required',
    'Retention policy must require service scope.',
);

echo "Event retention governance contracts passed.\n";
