<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('NEXA_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('NEXA_TEST_DB_PORT') ?: 3306);
$name = getenv('NEXA_TEST_DB_NAME') ?: '';
$user = getenv('NEXA_TEST_DB_USER') ?: '';
$password = getenv('NEXA_TEST_DB_PASSWORD') ?: '';
$configPath = getenv('NEXA_TEST_CONFIG') ?: $root . '/espocrm/data/config-internal.php';

if ($name === '' && is_file($configPath)) {
    $config = include $configPath;
    $database = $config['database'] ?? [];
    $host = $database['host'] ?? $host;
    $port = (int) ($database['port'] ?? $port);
    $name = $database['dbname'] ?? '';
    $user = $database['user'] ?? '';
    $password = $database['password'] ?? '';
}

if ($name === '') {
    throw new RuntimeException('Service ownership test database configuration is unavailable.');
}

$manifest = json_decode(
    (string) file_get_contents($root . '/database/shared/table-ownership-manifest.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$tables = $manifest['espoCoreConversion']['tenantScopedTables'] ?? [];

if (count($tables) !== 133) {
    throw new RuntimeException('The converted EspoCRM table inventory must contain 133 tables.');
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$columnQuery = $pdo->prepare(
    'SELECT is_nullable FROM information_schema.columns ' .
    'WHERE table_schema = :schema AND table_name = :table AND column_name = :column'
);

foreach ($tables as $table) {
    if (!is_string($table) || !preg_match('/^[a-z0-9_]+$/', $table)) {
        throw new RuntimeException('The ownership manifest contains an unsafe table name.');
    }

    foreach (['tenant_id', 'service_id'] as $column) {
        $columnQuery->execute(['schema' => $name, 'table' => $table, 'column' => $column]);
        $nullable = $columnQuery->fetchColumn();

        if ($nullable !== 'NO') {
            throw new RuntimeException("{$table}.{$column} must exist and be NOT NULL.");
        }
    }

    $missing = (int) $pdo->query(
        "SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = '' OR service_id = ''"
    )->fetchColumn();

    if ($missing !== 0) {
        throw new RuntimeException("{$table} contains {$missing} records without complete ownership.");
    }
}

$crmServiceId = '20000000-0000-4000-8000-000000000001';
$missingAssignments = $pdo->prepare(
    'SELECT COUNT(*) FROM nexa_tenant t LEFT JOIN nexa_tenant_service ts ' .
    'ON ts.tenant_id = t.id AND ts.service_id = :serviceId AND ts.status = :active ' .
    'WHERE ts.tenant_id IS NULL'
);
$missingAssignments->execute(['serviceId' => $crmServiceId, 'active' => 'active']);

if ((int) $missingAssignments->fetchColumn() !== 0) {
    throw new RuntimeException('Every tenant must have an active CRM service assignment.');
}

echo "Service ownership database tests passed.\n";