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
    throw new RuntimeException('Customer foundation test database configuration is unavailable.');
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$id = static fn (string $value): string => substr(hash('sha256', "nexa-customer-{$value}"), 0, 17);
$uuid = static function (string $value): string {
    $hash = hash('sha256', "nexa-customer-{$value}");

    return sprintf(
        '%s-%s-4%s-8%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        substr($hash, 17, 3),
        substr($hash, 20, 12)
    );
};
$serviceId = '20000000-0000-4000-8000-000000000001';
$tenants = [
    'a' => '30000000-0000-4000-8000-000000000001',
    'b' => '30000000-0000-4000-8000-000000000002',
];

$pdo->beginTransaction();
try {
    foreach ($tenants as $suffix => $tenantId) {
        $contactId = $id("contact-{$suffix}");
        $accountId = $id("account-{$suffix}");
        $relationshipTypeId = $uuid("relationship-type-{$suffix}");
        $definitionId = $uuid("lifecycle-definition-{$suffix}");
        $stageId = $uuid("lifecycle-stage-{$suffix}");
        $assignmentId = $uuid("lifecycle-assignment-{$suffix}");

        $pdo->prepare(
            'INSERT INTO account (id, name, deleted, tenant_id, service_id) VALUES (?, ?, 0, ?, ?)'
        )->execute([$accountId, "Customer Account {$suffix}", $tenantId, $serviceId]);
        $pdo->prepare(
            'INSERT INTO contact (id, first_name, last_name, account_id, deleted, tenant_id, service_id) ' .
            'VALUES (?, ?, ?, ?, 0, ?, ?)'
        )->execute([$contactId, 'Customer', strtoupper($suffix), $accountId, $tenantId, $serviceId]);
        $pdo->prepare(
            'INSERT INTO nexa_identity_link ' .
            '(id, tenant_id, contact_id, identity_type, normalized_value_hash, verification_status) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $uuid("identity-{$suffix}"),
            $tenantId,
            $contactId,
            'email',
            hash('sha256', "customer-{$suffix}@example.test"),
            'verified',
        ]);
        $pdo->prepare(
            'INSERT INTO nexa_relationship_type ' .
            '(id, tenant_id, type_key, label, source_entity_type, target_entity_type) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$relationshipTypeId, $tenantId, 'works_for', 'Works for', 'Contact', 'Account']);
        $pdo->prepare(
            'INSERT INTO nexa_relationship_edge ' .
            '(id, tenant_id, relationship_type_id, source_entity_type, source_entity_id, ' .
            'target_entity_type, target_entity_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $uuid("relationship-edge-{$suffix}"),
            $tenantId,
            $relationshipTypeId,
            'Contact',
            $contactId,
            'Account',
            $accountId,
        ]);
        $pdo->prepare(
            'INSERT INTO nexa_lifecycle_definition ' .
            '(id, tenant_id, definition_key, name, entity_type, is_default) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$definitionId, $tenantId, 'customer', 'Customer lifecycle', 'Contact']);
        $pdo->prepare(
            'INSERT INTO nexa_lifecycle_stage ' .
            '(id, tenant_id, lifecycle_definition_id, stage_key, name, category, position) ' .
            'VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([$stageId, $tenantId, $definitionId, 'new', 'New', 'new']);
        $pdo->prepare(
            'INSERT INTO nexa_lifecycle_assignment ' .
            '(id, tenant_id, lifecycle_definition_id, lifecycle_stage_id, entity_type, entity_id) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$assignmentId, $tenantId, $definitionId, $stageId, 'Contact', $contactId]);
        $pdo->prepare(
            'INSERT INTO nexa_lifecycle_transition ' .
            '(id, tenant_id, lifecycle_assignment_id, to_stage_id, source, actor_type) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $uuid("lifecycle-transition-{$suffix}"),
            $tenantId,
            $assignmentId,
            $stageId,
            'test',
            'system',
        ]);
        $pdo->prepare(
            'INSERT INTO nexa_timeline_event ' .
            '(id, tenant_id, contact_id, account_id, event_type, source_entity_type, ' .
            'source_entity_id, source_occurred_at, summary) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(6), ?)'
        )->execute([
            $uuid("timeline-{$suffix}"),
            $tenantId,
            $contactId,
            $accountId,
            'contact.created',
            'Contact',
            $contactId,
            "Customer {$suffix} created",
        ]);
    }

    foreach ($tenants as $suffix => $tenantId) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id = ? AND contact_id = ?'
        );
        $statement->execute([$tenantId, $id("contact-{$suffix}")]);
        $assert((int) $statement->fetchColumn() === 1, "Timeline scope failed for tenant {$suffix}.");
    }

    $crossTenant = $pdo->prepare(
        'UPDATE nexa_lifecycle_assignment SET entity_id = ? WHERE id = ? AND tenant_id = ?'
    );
    $crossTenant->execute([$id('contact-a'), $uuid('lifecycle-assignment-b'), $tenants['a']]);
    $assert($crossTenant->rowCount() === 0, 'Cross-tenant lifecycle update was not isolated.');

    $relationshipRejected = false;
    try {
        $pdo->prepare(
            'INSERT INTO nexa_relationship_edge ' .
            '(id, tenant_id, relationship_type_id, source_entity_type, source_entity_id, ' .
            'target_entity_type, target_entity_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $uuid('cross-tenant-relationship'),
            $tenants['a'],
            $uuid('relationship-type-b'),
            'Contact',
            $id('contact-a'),
            'Account',
            $id('account-a'),
        ]);
    } catch (PDOException) {
        $relationshipRejected = true;
    }
    $assert($relationshipRejected, 'A relationship type from another tenant was accepted.');

    $transitionRejected = false;
    try {
        $pdo->prepare(
            'INSERT INTO nexa_lifecycle_transition ' .
            '(id, tenant_id, lifecycle_assignment_id, to_stage_id, source, actor_type) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $uuid('cross-tenant-transition'),
            $tenants['a'],
            $uuid('lifecycle-assignment-b'),
            $uuid('lifecycle-stage-a'),
            'test',
            'system',
        ]);
    } catch (PDOException) {
        $transitionRejected = true;
    }
    $assert($transitionRejected, 'A lifecycle assignment from another tenant was accepted.');

    echo "Customer foundation database tests passed.\n";
} finally {
    $pdo->rollBack();
}
