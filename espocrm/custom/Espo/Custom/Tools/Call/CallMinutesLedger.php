<?php

namespace Espo\Custom\Tools\Call;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use PDO;
use RuntimeException;

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

    /** System default monthly share for a single user, drawn from the tenant's shared pool. */
    public const DEFAULT_USER_SHARE_MINUTES = 60;

    /** Sane bound for what a tenant admin may configure - guards against typos. */
    public const MAX_USER_SHARE_MINUTES = 2000;

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

    /**
     * Every user's default monthly share of the tenant's shared pool - a
     * second, tighter gate on top of the tenant-wide limit, so one user can't
     * quietly burn through the whole tenant's allocation on their own. Tenant
     * admin's own if set, else the system default.
     */
    public function userShareMinutes(string $tenantId, string $serviceId): int
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT user_call_share_minutes_override FROM nexa_tenant_service ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId LIMIT 1'
        );
        $statement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId]);
        $override = $statement->fetchColumn();

        return ($override === false || $override === null || (int) $override < 1)
            ? self::DEFAULT_USER_SHARE_MINUTES
            : (int) $override;
    }

    /** Sets (or clears, passing null) the tenant's default per-user share override. */
    public function setUserShareMinutes(string $tenantId, string $serviceId, ?int $minutes): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_tenant_service SET user_call_share_minutes_override = :minutes ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId'
        );
        $statement->execute(['minutes' => $minutes, 'tenantId' => $tenantId, 'serviceId' => $serviceId]);
    }

    /** This specific user's effective ceiling for the period: their share plus anything an admin has granted them. */
    public function userEffectiveLimit(string $tenantId, string $userId, string $serviceId, string $periodKey): int
    {
        $share = $this->userShareMinutes($tenantId, $serviceId);

        $grantStatement = $this->entityManager->getPDO()->prepare(
            'SELECT granted_minutes FROM nexa_user_credit_grant ' .
            'WHERE tenant_id = :tenantId AND user_id = :userId AND service_id = :serviceId AND period_key = :periodKey LIMIT 1'
        );
        $grantStatement->execute([
            'tenantId' => $tenantId,
            'userId' => $userId,
            'serviceId' => $serviceId,
            'periodKey' => $periodKey,
        ]);
        $granted = (int) $grantStatement->fetchColumn();

        return $share + $granted;
    }

    /**
     * How much headroom is left in the tenant's own shared pool right now -
     * what an admin's approval of a per-user request actually draws against.
     * Approving a request never creates new tenant-wide capacity, it only
     * gives one user permission to use more of what's already there - so
     * this counts not just actual usage but every outstanding grant already
     * handed out this period too, even ones nobody has drawn on yet. Without
     * that, two separate approvals could each pass the same stale balance
     * check and together hand out more permission than the pool can cover.
     */
    public function tenantPoolRemaining(string $tenantId, string $serviceId, string $periodKey): int
    {
        $pdo = $this->entityManager->getPDO();
        $limit = $this->effectiveLimit($tenantId, $serviceId, $periodKey);

        $usageStatement = $pdo->prepare(
            'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey LIMIT 1'
        );
        $usageStatement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
        $row = $usageStatement->fetch(PDO::FETCH_ASSOC);
        $used = $row ? (int) $row['quantity'] + (int) $row['reserved_quantity'] : 0;

        $grantedStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(granted_minutes), 0) FROM nexa_user_credit_grant ' .
            'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey'
        );
        $grantedStatement->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
        $granted = (int) $grantedStatement->fetchColumn();

        return max(0, $limit - $used - $granted);
    }

    /**
     * Raises one user's personal share for the period - the effect of an
     * admin approving their credit request. A single atomic upsert, safe
     * without its own transaction wrapper (matches claimSoftLimitNotice()'s
     * discipline elsewhere in this class).
     */
    public function grantUserCredit(string $tenantId, string $userId, string $serviceId, string $periodKey, int $minutes): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_user_credit_grant (tenant_id, user_id, service_id, period_key, granted_minutes) ' .
            'VALUES (:tenantId, :userId, :serviceId, :periodKey, :minutes) ' .
            'ON DUPLICATE KEY UPDATE granted_minutes = granted_minutes + VALUES(granted_minutes)'
        );
        $statement->execute([
            'tenantId' => $tenantId,
            'userId' => $userId,
            'serviceId' => $serviceId,
            'periodKey' => $periodKey,
            'minutes' => $minutes,
        ]);
    }

    /**
     * The tenant's real, always-finite ceiling for the period - hard_limit
     * from the plan (or a tenant-level override) plus anything a top-up has
     * added. There is no "unlimited" plan: every tenant has a real number
     * here, so a misconfigured plan/tenant row (no hard_limit resolvable at
     * all) is a data bug, not a valid unlimited state - it fails loudly
     * rather than silently granting unmetered calling.
     */
    public function effectiveLimit(string $tenantId, string $serviceId, string $periodKey): int
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
            throw new RuntimeException(
                "No calling-minutes hard_limit resolvable for tenant {$tenantId} / service {$serviceId} - " .
                'plan or tenant-service configuration is missing.'
            );
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
     * Atomically reserves a minute slice for a new call, against two
     * independent ceilings that both must have room: the tenant-wide shared
     * pool (as before), and this specific user's own monthly share of it -
     * a user can hit their personal share and be told to ask their admin
     * even while the tenant pool still has plenty left. Both counter rows
     * are locked within the same transaction so neither check can race with
     * a concurrent reservation. Returns the number of minutes actually
     * reserved for this call - capped at the tenant's per-call cap (or the
     * system default) so one call can never monopolize either ceiling.
     */
    public function reserveForCall(string $tenantId, string $userId, string $serviceId, string $periodKey): int
    {
        $tenantLimit = $this->effectiveLimit($tenantId, $serviceId, $periodKey);
        $userLimit = $this->userEffectiveLimit($tenantId, $userId, $serviceId, $periodKey);
        $perCallCap = $this->perCallCapMinutes($tenantId, $serviceId);
        $pdo = $this->entityManager->getPDO();

        $pdo->beginTransaction();

        try {
            $tenantSelect = $pdo->prepare(
                'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey ' .
                'FOR UPDATE'
            );
            $tenantSelect->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
            $tenantRow = $tenantSelect->fetch(PDO::FETCH_ASSOC);
            $tenantCommitted = $tenantRow ? (int) $tenantRow['quantity'] : 0;
            $tenantReserved = $tenantRow ? (int) $tenantRow['reserved_quantity'] : 0;

            if (($tenantCommitted + $tenantReserved) >= $tenantLimit) {
                $pdo->rollBack();

                throw new Forbidden('MINUTES_EXHAUSTED');
            }

            $userSelect = $pdo->prepare(
                'SELECT quantity, reserved_quantity FROM nexa_user_usage_counter ' .
                'WHERE tenant_id = :tenantId AND user_id = :userId AND service_id = :serviceId AND period_key = :periodKey ' .
                'FOR UPDATE'
            );
            $userSelect->execute([
                'tenantId' => $tenantId, 'userId' => $userId, 'serviceId' => $serviceId, 'periodKey' => $periodKey,
            ]);
            $userRow = $userSelect->fetch(PDO::FETCH_ASSOC);
            $userCommitted = $userRow ? (int) $userRow['quantity'] : 0;
            $userReserved = $userRow ? (int) $userRow['reserved_quantity'] : 0;

            if (($userCommitted + $userReserved) >= $userLimit) {
                $pdo->rollBack();

                throw new Forbidden('USER_SHARE_EXHAUSTED');
            }

            $reserveMinutes = min(
                $tenantLimit - $tenantCommitted - $tenantReserved,
                $userLimit - $userCommitted - $userReserved,
                $perCallCap
            );
            $reserveMinutes = max(1, $reserveMinutes);

            $tenantUpsert = $pdo->prepare(
                'INSERT INTO nexa_usage_counter (tenant_id, service_id, period_key, quantity, reserved_quantity) ' .
                'VALUES (:tenantId, :serviceId, :periodKey, 0, :reserveMinutes) ' .
                'ON DUPLICATE KEY UPDATE reserved_quantity = reserved_quantity + VALUES(reserved_quantity)'
            );
            $tenantUpsert->execute([
                'tenantId' => $tenantId,
                'serviceId' => $serviceId,
                'periodKey' => $periodKey,
                'reserveMinutes' => $reserveMinutes,
            ]);

            $userUpsert = $pdo->prepare(
                'INSERT INTO nexa_user_usage_counter (tenant_id, user_id, service_id, period_key, quantity, reserved_quantity) ' .
                'VALUES (:tenantId, :userId, :serviceId, :periodKey, 0, :reserveMinutes) ' .
                'ON DUPLICATE KEY UPDATE reserved_quantity = reserved_quantity + VALUES(reserved_quantity)'
            );
            $userUpsert->execute([
                'tenantId' => $tenantId,
                'userId' => $userId,
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
     * minutes and releases the FULL original reservation on both the
     * tenant-wide and per-user counters - any unused slice becomes
     * immediately available again on both ceilings.
     */
    public function settleCall(
        string $tenantId,
        string $userId,
        string $serviceId,
        string $periodKey,
        int $reservedMinutes,
        int $billedMinutes
    ): void {
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();

        try {
            $tenantSelect = $pdo->prepare(
                'SELECT quantity, reserved_quantity FROM nexa_usage_counter ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey ' .
                'FOR UPDATE'
            );
            $tenantSelect->execute(['tenantId' => $tenantId, 'serviceId' => $serviceId, 'periodKey' => $periodKey]);
            $tenantSelect->fetch(PDO::FETCH_ASSOC);

            $tenantUpdate = $pdo->prepare(
                'UPDATE nexa_usage_counter ' .
                'SET quantity = quantity + :billedMinutes, ' .
                '    reserved_quantity = GREATEST(0, reserved_quantity - :reservedMinutes) ' .
                'WHERE tenant_id = :tenantId AND service_id = :serviceId AND period_key = :periodKey'
            );
            $tenantUpdate->execute([
                'billedMinutes' => $billedMinutes,
                'reservedMinutes' => $reservedMinutes,
                'tenantId' => $tenantId,
                'serviceId' => $serviceId,
                'periodKey' => $periodKey,
            ]);

            $userSelect = $pdo->prepare(
                'SELECT quantity, reserved_quantity FROM nexa_user_usage_counter ' .
                'WHERE tenant_id = :tenantId AND user_id = :userId AND service_id = :serviceId AND period_key = :periodKey ' .
                'FOR UPDATE'
            );
            $userSelect->execute([
                'tenantId' => $tenantId, 'userId' => $userId, 'serviceId' => $serviceId, 'periodKey' => $periodKey,
            ]);
            $userSelect->fetch(PDO::FETCH_ASSOC);

            $userUpdate = $pdo->prepare(
                'UPDATE nexa_user_usage_counter ' .
                'SET quantity = quantity + :billedMinutes, ' .
                '    reserved_quantity = GREATEST(0, reserved_quantity - :reservedMinutes) ' .
                'WHERE tenant_id = :tenantId AND user_id = :userId AND service_id = :serviceId AND period_key = :periodKey'
            );
            $userUpdate->execute([
                'billedMinutes' => $billedMinutes,
                'reservedMinutes' => $reservedMinutes,
                'tenantId' => $tenantId,
                'userId' => $userId,
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
