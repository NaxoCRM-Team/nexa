<?php

namespace Espo\Custom\Tools\Call;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use PDO;

/**
 * The only place in the calling feature that runs multi-statement raw
 * transactions. `reserveForCall()`/`settleCall()` use `SELECT ... FOR UPDATE`
 * on the per-tenant-period usage-counter row (the same concurrency-safe
 * pattern already proven by `SignupService::enforceRateLimit()`) so that any
 * number of concurrent call attempts against the same tenant serialize at the
 * database level and can never over-admit the shared pool.
 *
 * Neither method ever touches EntityManager's ORM save path - that's a hard
 * rule, not an accident: EntityManager's TransactionManager tracks its own
 * transaction-nesting counter independent of a raw PDO transaction, and an
 * ORM write (e.g. creating a Notification) while a raw transaction is open
 * throws "There is already an active transaction". Keep it that way.
 */
final class CallMinutesLedger
{
    /** System default when a tenant hasn't set its own per-call cap. */
    public const DEFAULT_PER_CALL_CAP_MINUTES = 60;

    /** Sane bound for what a tenant admin may configure - guards against typos, not a real safety limit
     * (reserveForCall() already bounds any single call to whatever remains in the pool regardless of this cap). */
    public const MAX_PER_CALL_CAP_MINUTES = 480;

    public function __construct(private EntityManager $entityManager) {}

    /** Tenant's own per-call cap if set, else the system default. */
    public function perCallCapMinutes(string $tenantId, string $serviceId): int
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT per_call_cap_minutes_override FROM nexa_tenant_service ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId LIMIT 1'
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId]);
        $override = $statement->fetchColumn();

        return ($override === false || $override === null || (int) $override < 1)
            ? self::DEFAULT_PER_CALL_CAP_MINUTES
            : (int) $override;
    }

    /**
     * Sets (or clears, passing null) the tenant's per-call cap override.
     * Bounds enforcement (1..MAX_PER_CALL_CAP_MINUTES) is the caller's job -
     * this is a pure data write, matching the rest of this class's shape.
     */
    public function setPerCallCapMinutes(string $tenantId, string $serviceId, ?int $minutes): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_tenant_service SET per_call_cap_minutes_override = :minutes ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId'
        );
        $statement->execute(['minutes' => $minutes, 'tenantId' => $tenantId, 'serviceId' => $serviceId]);
    }

    /** @return int|null Effective minute limit for the period, or null if unlimited. */
    public function effectiveLimit(string $tenantId, string $serviceId, string $periodKey): ?int
    {
        $pdo = $this->entityManager->getPDO();

        $limitStatement = $pdo->prepare(
            'SELECT COALESCE(ts.hard_limit_override, ps.hard_limit) AS hard_limit ' .
            'FROM nexa_tenant_service ts ' .
            'LEFT JOIN nexa_tenant_subscription sub ON sub.tenant_id = ts.tenant_id ' .
            'LEFT JOIN nexa_plan_service ps ON ps.plan_id = sub.plan_id AND ps.service_id = ts.service_id ' .
            'WHERE ts.tenant_id = :tenantId AND ts.service_id = :serviceId LIMIT 1'
        );
        $limitStatement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId]);
        $hardLimit = $limitStatement->fetchColumn();

        if ($hardLimit === false || $hardLimit === null) {
            return null;
        }

        $grantStatement = $pdo->prepare(
            'SELECT granted_minutes FROM nexa_tenant_credit_grant ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey LIMIT 1'
        );
        $grantStatement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
        $granted = (int) $grantStatement->fetchColumn();

        return (int) $hardLimit + $granted;
    }

    /**
     * Claims the one-time "soft limit crossed" notice for this tenant/period,
     * if usage (committed + reserved) has reached the plan's soft_limit and
     * nobody has claimed it yet this period. Returns the usage snapshot only
     * on the call that actually wins the claim - every later call in the same
     * period gets null, so admins are notified exactly once per period no
     * matter how many calls cross the threshold concurrently.
     *
     * @return array{used: int, softLimit: int}|null
     */
    public function claimSoftLimitNotice(string $tenantId, string $serviceId, string $periodKey): ?array
    {
        $softLimit = $this->planSoftLimit($tenantId, $serviceId);

        if ($softLimit === null) {
            return null;
        }

        $pdo = $this->entityManager->getPDO();

        $usageStatement = $pdo->prepare(
            'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey LIMIT 1'
        );
        $usageStatement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
        $row = $usageStatement->fetch(PDO::FETCH_ASSOC);
        $used = $row ? (int) $row['quantity'] + (int) $row['reserved_quantity'] : 0;

        if ($used < $softLimit) {
            return null;
        }

        // Standard MySQL/MariaDB idiom for "insert if absent, tell me whether
        // it was new": a genuinely new row reports rowCount() === 1; a row
        // that already existed reports 0 because the SET clause is a no-op.
        $claim = $pdo->prepare(
            'INSERT INTO nexa_soft_limit_notice (tenant_id, service_id, period_key) ' .
            'VALUES (:tenantId, :serviceId, :periodKey) ' .
            'ON DUPLICATE KEY UPDATE tenant_id = tenant_id'
        );
        $claim->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);

        if ($claim->rowCount() !== 1) {
            return null;
        }

        return ['used' => $used, 'softLimit' => $softLimit];
    }

    private function planSoftLimit(string $tenantId, string $serviceId): ?int
    {
        // COALESCE(ts.soft_limit_override, ps.soft_limit), mirroring exactly
        // how effectiveLimit() resolves the hard limit - a tenant-level
        // override must win over the plan default here too.
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT COALESCE(ts.soft_limit_override, ps.soft_limit) AS soft_limit ' .
            'FROM nexa_tenant_service ts ' .
            'LEFT JOIN nexa_tenant_subscription sub ON sub.tenant_id = ts.tenant_id ' .
            'LEFT JOIN nexa_plan_service ps ON ps.plan_id = sub.plan_id AND ps.service_id = ts.service_id ' .
            'WHERE ts.tenant_id = :tenantId AND ts.service_id = :serviceId LIMIT 1'
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId]);
        $softLimit = $statement->fetchColumn();

        return ($softLimit === false || $softLimit === null) ? null : (int) $softLimit;
    }

    /**
     * Atomically reserves a minute slice for a new call. Throws Forbidden if
     * the tenant's pool (committed + already-reserved) is exhausted. Returns
     * the number of minutes actually reserved for this call - capped at the
     * tenant's per-call cap (or the system default) so one call can never
     * monopolize the pool.
     */
    public function reserveForCall(string $tenantId, string $serviceId, string $periodKey): int
    {
        $limit = $this->effectiveLimit($tenantId, $serviceId, $periodKey);
        $perCallCap = $this->perCallCapMinutes($tenantId, $serviceId);
        $pdo = $this->entityManager->getPDO();

        $pdo->beginTransaction();

        try {
            $select = $pdo->prepare(
                'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey ' .
                'FOR UPDATE'
            );
            $select->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $committed = $row ? (int) $row['quantity'] : 0;
            $reserved = $row ? (int) $row['reserved_quantity'] : 0;

            if ($limit !== null && ($committed + $reserved) >= $limit) {
                $pdo->rollBack();

                throw new Forbidden('MINUTES_EXHAUSTED');
            }

            $reserveMinutes = $limit === null
                ? $perCallCap
                : min($limit - $committed - $reserved, $perCallCap);
            $reserveMinutes = max(1, $reserveMinutes);

            $upsert = $pdo->prepare(
                'INSERT INTO nexa_usage_counter (tenant_id, service_id, period_key, quantity, reserved_quantity) ' .
                'VALUES (:tenantId, :serviceId, :periodKey, 0, :reserveMinutes) ' .
                'ON DUPLICATE KEY UPDATE reserved_quantity = reserved_quantity + VALUES(reserved_quantity)'
            );
            $upsert->execute([
                'tenantId' => $tenantId,
                'serviceId' => $serviceId,
                'periodKey' => $periodKey,
                'reserveMinutes' => $reserveMinutes,
            ]);

            $pdo->commit();

            return $reserveMinutes;
        } catch (Forbidden $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Settles a finished (or abandoned) call: commits the actually-billed
     * minutes and releases the FULL original reservation - any unused slice
     * becomes immediately available to other callers.
     */
    public function settleCall(
        string $tenantId,
        string $serviceId,
        string $periodKey,
        int $reservedMinutes,
        int $billedMinutes
    ): void {
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();

        try {
            $select = $pdo->prepare(
                'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey ' .
                'FOR UPDATE'
            );
            $select->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
            $select->fetch(PDO::FETCH_ASSOC);

            $update = $pdo->prepare(
                'UPDATE nexa_usage_counter ' .
                'SET quantity = quantity + :billedMinutes, ' .
                '    reserved_quantity = GREATEST(0, reserved_quantity - :reservedMinutes) ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey'
            );
            $update->execute([
                'billedMinutes' => $billedMinutes,
                'reservedMinutes' => $reservedMinutes,
                'tenantId' => $tenantId,
                'serviceId' => $serviceId,
                'periodKey' => $periodKey,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
