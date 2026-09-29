<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\PublicAccess;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use PDO;

/** Persistent per-tenant limits for unauthenticated write and tracking surfaces. */
final class PublicRequestLimiter
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    public function enforce(
        string $tenantId,
        string $serviceId,
        string $scope,
        int $limit,
        int $windowSeconds,
        int $blockSeconds = 600,
    ): void {
        $scope = mb_substr(trim($scope), 0, 160);
        if ($tenantId === '' || $serviceId === '' || $scope === '' || $limit < 1 || $windowSeconds < 1) {
            throw new \LogicException('A public request limit is not configured correctly.');
        }

        $pdo = $this->entityManager->getPDO();
        $fingerprint = $this->fingerprint($tenantId, $serviceId, $scope);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $seed = $pdo->prepare(
                'INSERT IGNORE INTO nexa_public_rate_limit ' .
                '(tenant_id,service_id,scope_key,fingerprint_hash,window_started_at,request_count) VALUES (?,?,?,?,?,0)'
            );
            $seed->execute([$tenantId, $serviceId, $scope, $fingerprint, $now->format('Y-m-d H:i:s.u')]);
            $select = $pdo->prepare(
                'SELECT window_started_at,request_count,blocked_until FROM nexa_public_rate_limit ' .
                'WHERE tenant_id=? AND service_id=? AND scope_key=? AND fingerprint_hash=? FOR UPDATE'
            );
            $select->execute([$tenantId, $serviceId, $scope, $fingerprint]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('The public request counter could not be initialized.');
            }

            if ($row && $row['blocked_until'] !== null && new \DateTimeImmutable((string) $row['blocked_until'], new \DateTimeZone('UTC')) > $now) {
                throw new PublicRateLimitExceeded('Too many requests. Try again later.');
            }

            $windowStarted = new \DateTimeImmutable((string) $row['window_started_at'], new \DateTimeZone('UTC'));
            $expired = $windowStarted->modify("+{$windowSeconds} seconds") <= $now;
            $count = $expired ? 1 : ((int) $row['request_count'] + 1);
            $blockedUntil = $count > $limit ? $now->modify("+{$blockSeconds} seconds") : null;

            $update = $pdo->prepare(
                'UPDATE nexa_public_rate_limit SET window_started_at=?,request_count=?,blocked_until=? ' .
                'WHERE tenant_id=? AND service_id=? AND scope_key=? AND fingerprint_hash=?'
            );
            $update->execute([
                ($expired ? $now : $windowStarted)->format('Y-m-d H:i:s.u'),
                $count,
                $blockedUntil?->format('Y-m-d H:i:s.u'),
                $tenantId,
                $serviceId,
                $scope,
                $fingerprint,
            ]);

            if (random_int(1, 100) === 1) {
                $pdo->exec('DELETE FROM nexa_public_rate_limit WHERE updated_at < DATE_SUB(NOW(6), INTERVAL 2 DAY)');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            if ($blockedUntil !== null) {
                throw new PublicRateLimitExceeded('Too many requests. Try again later.');
            }
        } catch (PublicRateLimitExceeded $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function fingerprint(string $tenantId, string $serviceId, string $scope): string
    {
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $userAgent = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')), 0, 500);
        $secret = (string) $this->config->get('hashSecretKey', '');
        if ($secret === '') {
            $secret = $tenantId;
        }

        return hash_hmac('sha256', implode('|', [$tenantId, $serviceId, $scope, $remoteAddress, $userAgent]), $secret);
    }
}
