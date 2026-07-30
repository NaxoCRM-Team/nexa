<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);

    if (!is_string($content)) {
        throw new RuntimeException("Missing schema contract: {$relative}");
    }

    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$audit = $read('docs/architecture/existing-schema-audit.md');
$migration = $read('database/shared/migrations/0009_unified_customer_foundation.sql');
$manifest = json_decode(
    $read('database/shared/table-ownership-manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

preg_match_all('/^\| (\d+) \|/m', $audit, $matches);
$mappedSections = array_map('intval', $matches[1] ?? []);
$assert($mappedSections === range(1, 47), 'The schema audit must map all 47 specification sections in order.');

$tables = [
    'nexa_identity_link',
    'nexa_relationship_type',
    'nexa_relationship_edge',
    'nexa_lifecycle_definition',
    'nexa_lifecycle_stage',
    'nexa_lifecycle_assignment',
    'nexa_lifecycle_transition',
    'nexa_timeline_event',
];

$manifestTables = [];
foreach ($manifest['tables'] ?? [] as $entry) {
    $manifestTables[$entry['name'] ?? ''] = $entry;
}

foreach ($tables as $table) {
    $assert(
        str_contains($migration, "CREATE TABLE IF NOT EXISTS {$table}"),
        "Migration is missing {$table}."
    );
    $assert(
        ($manifestTables[$table]['classification'] ?? '') === 'tenantOwned',
        "{$table} must be tenantOwned."
    );
}

$assert(
    substr_count($migration, 'tenant_id CHAR(36) NOT NULL') === count($tables),
    'Every Customer 360 foundation table must require tenant_id.'
);
$assert(
    !str_contains($migration, 'service_id'),
    'Shared customer primitives must not be tied to one service entitlement.'
);
$assert(
    str_contains($audit, 'No generic customer table'),
    'The audit must prohibit a duplicate customer master.'
);

echo "Schema requirements mapping tests passed.\n";
