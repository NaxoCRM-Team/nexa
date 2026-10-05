<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Tenant\TenantContext;
use PDO;

final class MarketingAllowance
{
    public static function limit(PDO $pdo, TenantContext $context, int $providerCap): int
    {
        $query = $pdo->prepare("SELECT COALESCE(ts.hard_limit_override,ps.hard_limit) AS contact_tier
            FROM nexa_tenant_subscription sub
            INNER JOIN nexa_plan_definition plan ON plan.id=sub.plan_id AND plan.status='active'
            INNER JOIN nexa_service_definition sd ON sd.service_key='marketing.contacts' AND sd.status='active'
            INNER JOIN nexa_plan_service ps ON ps.plan_id=sub.plan_id AND ps.service_id=sd.id AND ps.is_enabled=1
            INNER JOIN nexa_tenant_service ts ON ts.tenant_id=sub.tenant_id AND ts.service_id=sd.id AND ts.status='active'
            WHERE sub.tenant_id=? AND sub.status IN ('active','trialing')
            AND (sub.status='active' OR sub.trial_ends_at>NOW())
            AND (ts.starts_at IS NULL OR ts.starts_at<=NOW()) AND (ts.ends_at IS NULL OR ts.ends_at>NOW())
            LIMIT 1");
        $query->execute([$context->tenantId]);
        $tier = $query->fetchColumn();
        return self::calculate($tier === false || $tier === null ? null : (int) $tier, $providerCap);
    }

    public static function calculate(?int $contactTier, int $providerCap): int
    {
        // Missing entitlements must never turn into unlimited sending.
        if ($contactTier === null || $contactTier <= 0) return 0;
        $allowance = min($contactTier, intdiv(PHP_INT_MAX, 20)) * 20;
        return $providerCap > 0 ? min($allowance, $providerCap) : $allowance;
    }
}
