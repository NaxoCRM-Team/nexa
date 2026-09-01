<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\ORM\EntityManager;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$application = new Application();
$application->setupSystemUser();
$pdo = $application->getContainer()->getByClass(EntityManager::class)->getPDO();
$tenants = [
    '30000000-0000-4000-8000-000000000001' => 'isolation-alpha',
    '30000000-0000-4000-8000-000000000002' => 'isolation-beta',
];

foreach ($tenants as $tenantId => $slug) {
    $scalar = static function (string $sql, array $params = []) use ($pdo): int {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    };
    $assert($scalar('SELECT COUNT(*) FROM `case` WHERE tenant_id=? AND service_id=? AND deleted=0', [$tenantId, '20000000-0000-4000-8000-000000000001']) >= 3, "{$slug} is missing representative Cases.");
    $assert($scalar("SELECT COUNT(*) FROM `case` WHERE tenant_id=? AND is_internal=0 AND account_id IS NOT NULL AND contact_id IS NOT NULL AND opportunity_id IS NOT NULL AND sla_policy_id IS NOT NULL", [$tenantId]) >= 2, "{$slug} external Cases are missing native customer or commercial links.");
    $assert($scalar('SELECT COUNT(*) FROM `case` WHERE tenant_id=? AND is_internal=1', [$tenantId]) >= 1, "{$slug} is missing an internal Case for Portal ACL verification.");
    $assert($scalar('SELECT COUNT(*) FROM portal WHERE tenant_id=? AND custom_id=? AND is_active=1 AND deleted=0', [$tenantId, $slug . '-support']) === 1, "{$slug} customer Portal is missing.");
    $assert($scalar('SELECT COUNT(*) FROM portal_role WHERE tenant_id=? AND name=? AND deleted=0', [$tenantId, 'Customer Case Access']) === 1, "{$slug} Portal Case role is missing.");
    $assert($scalar("SELECT COUNT(*) FROM user WHERE tenant_id=? AND type='portal' AND contact_id IS NOT NULL AND is_active=1 AND deleted=0", [$tenantId]) === 1, "{$slug} Portal user is missing its native Contact link.");
    $assert($scalar('SELECT COUNT(*) FROM portal_user pu JOIN portal p ON p.id=pu.portal_id AND p.tenant_id=pu.tenant_id JOIN user u ON u.id=pu.user_id AND u.tenant_id=pu.tenant_id WHERE pu.tenant_id=? AND pu.deleted=0', [$tenantId]) === 1, "{$slug} Portal-to-user relationship is invalid.");
    $assert($scalar('SELECT COUNT(*) FROM account_portal_user apu JOIN user u ON u.id=apu.user_id AND u.tenant_id=apu.tenant_id JOIN account a ON a.id=apu.account_id AND a.tenant_id=apu.tenant_id WHERE apu.tenant_id=? AND apu.deleted=0', [$tenantId]) === 1, "{$slug} Portal user is missing its native Account relationship.");
    $assert($scalar('SELECT COUNT(*) FROM nexa_case_sla_policy WHERE tenant_id=? AND service_id=? AND is_active=1', [$tenantId, '20000000-0000-4000-8000-000000000001']) >= 4, "{$slug} is missing default Case SLA policies.");
}

$crossTenantLinks = (int) $pdo->query(
    'SELECT COUNT(*) FROM `case` c LEFT JOIN account a ON a.id=c.account_id LEFT JOIN contact ct ON ct.id=c.contact_id ' .
    'WHERE c.deleted=0 AND ((a.id IS NOT NULL AND a.tenant_id<>c.tenant_id) OR (ct.id IS NOT NULL AND ct.tenant_id<>c.tenant_id))'
)->fetchColumn();
$assert($crossTenantLinks === 0, 'A demo Case links to another tenant customer record.');

echo "Two-tenant Case and Portal demo fixtures passed.\n";
