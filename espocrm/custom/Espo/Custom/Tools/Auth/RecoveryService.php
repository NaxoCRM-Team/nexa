<?php

namespace Espo\Custom\Tools\Auth;

use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\Tools\UserSecurity\Password\RecoveryService as CoreRecoveryService;
use PDO;
use Throwable;

/** Resolves a recovery identity before entering its tenant-scoped core flow. */
final class RecoveryService
{
    private const DEFAULT_RESPONSE_DELAY_MS = 3000;

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private CoreRecoveryService $coreRecoveryService,
        private Config $config,
        private Log $log,
    ) {}

    /** @return array{status:string,message:string} */
    public function request(string $email): array
    {
        $startedAt = microtime(true);
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new AuthProblem(422, 'validation_failed', 'Enter a valid email address.', ['email' => 'Enter a valid email address.']);
        }

        try {
            $identity = $this->resolveIdentity($email);

            if ($identity !== null) {
                $url = rtrim((string) $this->config->get('siteUrl', ''), '/') . '/login?source=recovery';
                $this->tenantContextStore->runWith(
                    $identity['tenant'],
                    fn () => $this->coreRecoveryService->request($email, $identity['username'], $url)
                );
            }
        } catch (Throwable $e) {
            // Recovery responses remain neutral. Operational detail belongs in
            // protected logs and must never become an account-discovery signal.
            $this->log->warning(
                'Nexa password recovery request was not delivered: ' . $e::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );
        } finally {
            $this->applyNeutralDelay($startedAt);
        }

        return [
            'status' => 'accepted',
            'message' => 'If the email matches an account, password reset instructions will be sent.',
        ];
    }

    /** @return array{tenant:TenantContext,username:string}|null */
    private function resolveIdentity(string $email): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT DISTINCT t.id, t.slug, t.display_name, u.user_name FROM `user` u ' .
            'INNER JOIN nexa_tenant t ON t.id = u.tenant_id ' .
            'INNER JOIN entity_email_address eea ON eea.entity_id = u.id AND eea.entity_type = \'User\' AND eea.deleted = 0 AND eea.tenant_id = u.tenant_id ' .
            'INNER JOIN email_address ea ON ea.id = eea.email_address_id AND ea.deleted = 0 AND ea.tenant_id = u.tenant_id ' .
            'WHERE ea.`lower` = :email AND u.deleted = 0 AND u.is_active = 1 ' .
            'AND t.status = :active LIMIT 2'
        );
        $statement->execute(['email' => $email, 'active' => 'active']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Legacy duplicates must not select an arbitrary tenant or disclose that
        // the email exists. New signup paths reserve owner emails globally.
        if (count($rows) !== 1) {
            return null;
        }

        return [
            'tenant' => new TenantContext($rows[0]['id'], $rows[0]['slug'], 'recovery-identity', $rows[0]['display_name']),
            'username' => $rows[0]['user_name'],
        ];
    }

    private function applyNeutralDelay(float $startedAt): void
    {
        $targetMs = (int) ($this->config->get('passwordRecoveryRequestDelay') ?? self::DEFAULT_RESPONSE_DELAY_MS);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($elapsedMs < $targetMs) {
            usleep(($targetMs - $elapsedMs) * 1000);
        }
    }
}
