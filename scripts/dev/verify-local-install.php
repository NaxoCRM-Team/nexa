<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$configPath = $root . '/espocrm/data/config-internal.php';
$beforeDemo = in_array('--before-demo', $argv, true);

if (!is_file($configPath)) {
    fwrite(STDERR, "Application configuration is missing. Run setup-native-windows.ps1.\n");
    exit(1);
}

$config = include $configPath;

if (($config['isInstalled'] ?? false) !== true) {
    fwrite(STDERR, "Native application installation has not completed.\n");
    exit(1);
}

$database = $config['database'] ?? null;
if (!is_array($database)) {
    fwrite(STDERR, "Database configuration is missing.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $database['host'],
    $database['port'] ?? 3306,
    $database['dbname'],
);
$pdo = new PDO(
    $dsn,
    $database['user'],
    $database['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$scalar = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$minimums = [
    'tables' => [$scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'), 197],
    'tenant columns' => [$scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'tenant_id'"), 184],
    'service columns' => [$scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'service_id'"), 166],
];

foreach ($minimums as $label => [$actual, $minimum]) {
    if ($actual < $minimum) {
        fwrite(STDERR, "Incomplete database: expected at least {$minimum} {$label}, found {$actual}.\n");
        exit(1);
    }
}

$expectedMigrations = array_map('basename', glob($root . '/database/shared/migrations/*.sql') ?: []);
$appliedMigrations = $pdo->query('SELECT migration_id FROM nexa_schema_migration')->fetchAll(PDO::FETCH_COLUMN);
$missingMigrations = array_diff($expectedMigrations, $appliedMigrations);
if ($missingMigrations !== []) {
    fwrite(STDERR, 'Missing migrations: ' . implode(', ', $missingMigrations) . "\n");
    exit(1);
}

$ownershipManifest = json_decode(
    (string) file_get_contents($root . '/database/shared/table-ownership-manifest.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$convertedTables = $ownershipManifest['espoCoreConversion']['tenantScopedTables'] ?? [];
$columnStatement = $pdo->prepare(
    "SELECT is_nullable FROM information_schema.columns " .
    "WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'service_id'"
);

// A successful setup must enforce complete ownership on every converted Espo table.
foreach ($convertedTables as $table) {
    $columnStatement->execute([$table]);
    if ($columnStatement->fetchColumn() !== 'NO') {
        fwrite(STDERR, "Incomplete ownership schema: {$table}.service_id must be NOT NULL.\n");
        exit(1);
    }
}

if (!$beforeDemo) {
    $demoTenantIds = [
        '30000000-0000-4000-8000-000000000001',
        '30000000-0000-4000-8000-000000000002',
    ];
    $tenantStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM nexa_tenant WHERE id = ? AND status = 'active'"
    );
    $adminStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM user WHERE tenant_id = ? AND service_id = ? AND type = 'admin' AND is_active = 1 AND deleted = 0"
    );

    foreach ($demoTenantIds as $tenantId) {
        $tenantStatement->execute([$tenantId]);
        $adminStatement->execute([$tenantId, '20000000-0000-4000-8000-000000000001']);
        if ((int) $tenantStatement->fetchColumn() !== 1 || (int) $adminStatement->fetchColumn() < 1) {
            fwrite(STDERR, "Demo tenant {$tenantId} or its administrator is missing.\n");
            exit(1);
        }

        foreach (['account', 'contact', 'lead', 'opportunity', 'task', 'meeting'] as $table) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ? AND service_id = ?");
            $statement->execute([$tenantId, '20000000-0000-4000-8000-000000000001']);
            if ((int) $statement->fetchColumn() < 1) {
                fwrite(STDERR, "Demo table {$table} has no records for tenant {$tenantId}.\n");
                exit(1);
            }
        }
    }
}

echo sprintf(
    "Local installation verified: %d tables, %d tenant columns, %d service columns, %d migrations%s.\n",
    $minimums['tables'][0],
    $minimums['tenant columns'][0],
    $minimums['service columns'][0],
    count($appliedMigrations),
    $beforeDemo ? '' : ', two demo tenants with CRM data',
);
