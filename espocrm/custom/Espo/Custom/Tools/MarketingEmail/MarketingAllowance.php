<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Tenant\TenantContext;
use PDO;

final class MarketingAllowance
{
    public static function limit(PDO $pdo, TenantContext $context, int $providerCap): int
    {
        return self::calculate(self::entitlement($pdo, $context, 'marketing.contacts'), $providerCap);
    }

    public static function transactionalLimit(PDO $pdo, TenantContext $context, int $providerCap): int
    {
        return self::calculateTransactional(self::entitlement($pdo, $context, 'email.transactional'), $providerCap);
    }

    private static function entitlement(PDO $pdo, TenantContext $context, string $serviceKey): ?int
    {
        $query = $pdo->prepare("SELECT COALESCE(ts.hard_limit_override,ps.hard_limit) AS contact_tier
            FROM nexa_tenant_subscription sub
            INNER JOIN nexa_plan_definition plan ON plan.id=sub.plan_id AND plan.status='active'
            INNER JOIN nexa_service_definition sd ON sd.service_key=? AND sd.status='active'
            INNER JOIN nexa_plan_service ps ON ps.plan_id=sub.plan_id AND ps.service_id=sd.id AND ps.is_enabled=1
            INNER JOIN nexa_tenant_service ts ON ts.tenant_id=sub.tenant_id AND ts.service_id=sd.id AND ts.status='active'
            WHERE sub.tenant_id=? AND sub.status IN ('active','trialing')
            AND (sub.status='active' OR sub.trial_ends_at>NOW())
            AND (ts.starts_at IS NULL OR ts.starts_at<=NOW()) AND (ts.ends_at IS NULL OR ts.ends_at>NOW())
            LIMIT 1");
        $query->execute([$serviceKey, $context->tenantId]);
        $tier = $query->fetchColumn();
        return $tier === false || $tier === null ? null : (int) $tier;
    }

    public static function calculate(?int $contactTier, int $providerCap): int
    {
        // Missing entitlements must never turn into unlimited sending.
        if ($contactTier === null || $contactTier <= 0) return 0;
        $allowance = min($contactTier, intdiv(PHP_INT_MAX, 20)) * 20;
        return $providerCap > 0 ? min($allowance, $providerCap) : $allowance;
    }

    public static function calculateTransactional(?int $messageLimit, int $providerCap): int
    {
        if ($messageLimit === null || $messageLimit <= 0) return 0;
        return $providerCap > 0 ? min($messageLimit, $providerCap) : $messageLimit;
    }
}
