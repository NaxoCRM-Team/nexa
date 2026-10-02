<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$migrations = $read('database/shared/migrations/0053_add_behavior_event_foundation.sql')
    . $read('database/shared/migrations/0057_harden_behavior_event_contract.sql')
    . $read('database/shared/migrations/0058_add_behavior_event_replay.sql')
    . $read('database/shared/migrations/0060_scope_timeline_and_backfill_identity.sql');
$service = $read('espocrm/custom/Espo/Custom/Tools/Event/BehaviorEventService.php');
$replay = $read('espocrm/custom/Espo/Custom/Tools/Event/BehaviorEventReplayService.php');
$contract = $read('espocrm/custom/Espo/Custom/Tools/Event/EventContract.php');
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');

foreach (['nexa_visitor_identity', 'nexa_behavior_event', 'nexa_behavior_event_replay'] as $table) {
    $assert(str_contains($migrations, $table), "Missing {$table}.");
}
foreach ([
    'idempotency_key',
    'event_version',
    'event_category',
    'consent_category',
    'correlation_id',
    'resolution_evidence_hash',
] as $field) {
    $assert(str_contains($migrations, $field), "Missing {$field} contract.");
}
foreach ([
    'nexa_timeline_event',
    'nexa_outbox_event',
    'tenant_id = ?',
    'service_id = ?',
    'FOR UPDATE',
] as $needle) {
    $assert(str_contains($service, $needle), "Event service missing {$needle}.");
}
foreach (['STANDARD_EVENTS', 'custom\\.', 'identityEvidence', 'consent is required'] as $needle) {
    $assert(str_contains($contract, $needle), "Event validation missing {$needle}.");
}
foreach (['behavior.event.replay.requested', 'replayKey', 'isAdmin', 'SecurityAuditService'] as $needle) {
    $assert(str_contains($replay, $needle), "Event replay missing {$needle}.");
}
foreach (['/Nexa/events', '/Nexa/events/:id/replay'] as $route) {
    $assert(str_contains($routes, $route), "Event route missing {$route}.");
}

echo "Behavior event contracts passed.\n";
