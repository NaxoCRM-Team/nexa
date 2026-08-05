<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('NEXA_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('NEXA_TEST_DB_PORT') ?: 3306);
$name = getenv('NEXA_TEST_DB_NAME') ?: '';
$user = getenv('NEXA_TEST_DB_USER') ?: '';
$password = getenv('NEXA_TEST_DB_PASSWORD') ?: '';

// Native installations can point at their ignored configuration without copying credentials.
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
    throw new RuntimeException('CRM smoke test database configuration is unavailable.');
}

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$tenantA = '30000000-0000-4000-8000-000000000001';
$tenantB = '30000000-0000-4000-8000-000000000002';
$serviceId = '20000000-0000-4000-8000-000000000001';
$id = static fn (string $value): string => substr(hash('sha256', "nexa-smoke-{$value}"), 0, 17);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo->beginTransaction();
try {
    foreach ([$tenantA => 'a', $tenantB => 'b'] as $tenant => $suffix) {
        $account = $id("account-{$suffix}");
        $contact = $id("contact-{$suffix}");
        $lead = $id("lead-{$suffix}");
        $opportunity = $id("opportunity-{$suffix}");
        $export = $id("export-{$suffix}");

        $pdo->prepare('INSERT INTO account (id, name, deleted, tenant_id, service_id) VALUES (?, ?, 0, ?, ?)')->execute([$account, "Smoke Account {$suffix}", $tenant, $serviceId]);
        $pdo->prepare('INSERT INTO contact (id, first_name, last_name, account_id, deleted, tenant_id, service_id) VALUES (?, ?, ?, ?, 0, ?, ?)')->execute([$contact, 'Smoke', strtoupper($suffix), $account, $tenant, $serviceId]);
        $pdo->prepare('INSERT INTO lead (id, first_name, last_name, deleted, tenant_id, service_id) VALUES (?, ?, ?, 0, ?, ?)')->execute([$lead, 'Lead', strtoupper($suffix), $tenant, $serviceId]);
        $pdo->prepare('INSERT INTO opportunity (id, name, account_id, contact_id, deleted, tenant_id, service_id) VALUES (?, ?, ?, ?, 0, ?, ?)')->execute([$opportunity, "Smoke Deal {$suffix}", $account, $contact, $tenant, $serviceId]);
        $pdo->prepare('INSERT INTO account_contact (account_id, contact_id, deleted, tenant_id, service_id) VALUES (?, ?, 0, ?, ?)')->execute([$account, $contact, $tenant, $serviceId]);
        $pdo->prepare('INSERT INTO contact_opportunity (contact_id, opportunity_id, deleted, tenant_id, service_id) VALUES (?, ?, 0, ?, ?)')->execute([$contact, $opportunity, $tenant, $serviceId]);
        $pdo->prepare('INSERT INTO export (id, status, params, deleted, tenant_id, service_id) VALUES (?, ?, ?, 0, ?, ?)')->execute([$export, 'Running', json_encode(['entityType' => 'Account']), $tenant, $serviceId]);
    }

    foreach ([$tenantA, $tenantB] as $tenant) {
        foreach (['account', 'contact', 'lead', 'opportunity', 'export'] as $table) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = ? AND service_id = ? AND deleted = 0");
            $statement->execute([$tenant, $serviceId]);
            $assert((int) $statement->fetchColumn() >= 1, "{$table} read scope failed for {$tenant}.");
        }
    }

    $crossUpdate = $pdo->prepare('UPDATE account SET name = ? WHERE id = ? AND tenant_id = ? AND service_id = ?');
    $crossUpdate->execute(['Forbidden', $id('account-b'), $tenantA, $serviceId]);
    $assert($crossUpdate->rowCount() === 0, 'Cross-tenant update was not isolated.');
    $crossDelete = $pdo->prepare('DELETE FROM contact WHERE id = ? AND tenant_id = ? AND service_id = ?');
    $crossDelete->execute([$id('contact-b'), $tenantA, $serviceId]);
    $assert($crossDelete->rowCount() === 0, 'Cross-tenant delete was not isolated.');

    $relationship = $pdo->prepare('SELECT COUNT(*) FROM account_contact ac JOIN account a ON a.id = ac.account_id AND a.tenant_id = ac.tenant_id AND a.service_id = ac.service_id JOIN contact c ON c.id = ac.contact_id AND c.tenant_id = ac.tenant_id AND c.service_id = ac.service_id WHERE ac.tenant_id = ? AND ac.service_id = ? AND ac.account_id = ? AND ac.contact_id = ?');
    $relationship->execute([$tenantA, $serviceId, $id('account-a'), $id('contact-a')]);
    $assert((int) $relationship->fetchColumn() === 1, 'Tenant-qualified relationship is missing.');

    echo "Tenant CRM database smoke tests passed.\n";
} finally {
    $pdo->rollBack();
}
