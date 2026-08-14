<?php

namespace Espo\Core\Tenant;

use Espo\ORM\EntityManager;

final class TenantResolver
{
    public function __construct(private EntityManager $entityManager)
    {}

    public function resolveHost(string $host): ?TenantContext
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($host, FILTER_VALIDATE_IP))) {
            return null;
        }

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT t.id, t.slug, t.display_name, ts.service_id FROM nexa_tenant_domain d ' .
            'INNER JOIN nexa_tenant t ON t.id = d.tenant_id ' .
            $this->crmServiceJoin() .
            'WHERE d.hostname = :hostname AND d.verification_status = :verified ' .
            'AND t.status = :tenantStatus LIMIT 1'
        );
        $statement->execute([
            'hostname' => $host,
            'verified' => 'verified',
            'tenantStatus' => 'active',
            'tenantServiceStatus' => 'active',
            'serviceStatus' => 'active',
            'serviceKey' => 'crm',
        ]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->contextFromRow($row, 'verified-host') : null;
    }

    public function resolveLoginIdentifier(string $identifier): ?TenantContext
    {
        $identifier = trim($identifier);

        if ($identifier === '' || strlen($identifier) > 255) {
            return null;
        }

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $normalizedEmail = $isEmail ? strtolower($identifier) : null;

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT DISTINCT t.id, t.slug, t.display_name, ts.service_id FROM user u ' .
            'INNER JOIN nexa_tenant t ON t.id = u.tenant_id ' .
            $this->crmServiceJoin() .
            'WHERE ((:emailMode = 1 AND u.login_email = :email) ' .
            'OR (:legacyMode = 1 AND u.login_email IS NULL AND u.user_name = :identifier)) ' .
            'AND u.service_id = ts.service_id ' .
            'AND u.deleted = 0 AND u.is_active = 1 AND t.status = :tenantStatus LIMIT 2'
        );
        $statement->execute([
            'identifier' => $identifier,
            'email' => $normalizedEmail,
            'emailMode' => $isEmail ? 1 : 0,
            'legacyMode' => $isEmail ? 0 : 1,
            'tenantStatus' => 'active',
            'tenantServiceStatus' => 'active',
            'serviceStatus' => 'active',
            'serviceKey' => 'crm',
        ]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (count($rows) !== 1) {
            return null;
        }

        return $this->contextFromRow($rows[0], 'login-identity');
    }

    public function resolveAuthToken(string $token): ?TenantContext
    {
        $token = trim($token);

        if ($token === '' || strlen($token) > 64) {
            return null;
        }

        // Browser entry points do not carry API authorization headers. Resolve
        // their tenant from the opaque native auth-token cookie before the
        // entry-point authentication service validates that token.
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT DISTINCT t.id, t.slug, t.display_name, ts.service_id FROM auth_token a ' .
            'INNER JOIN user u ON u.id = a.user_id AND u.tenant_id = a.tenant_id ' .
            'AND u.service_id = a.service_id ' .
            'INNER JOIN nexa_tenant t ON t.id = a.tenant_id ' .
            $this->crmServiceJoin() .
            'WHERE a.token = :token AND a.deleted = 0 AND a.is_active = 1 ' .
            'AND a.portal_id IS NULL AND a.service_id = ts.service_id ' .
            'AND u.deleted = 0 AND u.is_active = 1 AND t.status = :tenantStatus LIMIT 2'
        );
        $statement->execute([
            'token' => $token,
            'tenantStatus' => 'active',
            'tenantServiceStatus' => 'active',
            'serviceStatus' => 'active',
            'serviceKey' => 'crm',
        ]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (count($rows) !== 1) {
            return null;
        }

        return $this->contextFromRow($rows[0], 'auth-token-cookie');
    }

    public function resolvePasswordChangeRequest(string $requestId): ?TenantContext
    {
        $requestId = trim($requestId);
        if ($requestId === '' || strlen($requestId) > 64) {
            return null;
        }

        // The opaque, single-use request ID is the only tenant signal available
        // on the shared reset domain. Resolve it before tenant-scoped ORM work.
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT DISTINCT t.id, t.slug, t.display_name, ts.service_id FROM password_change_request pcr ' .
            'INNER JOIN nexa_tenant t ON t.id = pcr.tenant_id ' .
            $this->crmServiceJoin() .
            'WHERE pcr.request_id = :requestId AND pcr.deleted = 0 ' .
            'AND pcr.service_id = ts.service_id AND t.status = :tenantStatus LIMIT 2'
        );
        $statement->execute([
            'requestId' => $requestId,
            'tenantStatus' => 'active',
            'tenantServiceStatus' => 'active',
            'serviceStatus' => 'active',
            'serviceKey' => 'crm',
        ]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (count($rows) !== 1) {
            return null;
        }

        return $this->contextFromRow($rows[0], 'password-reset-request');
    }

    private function crmServiceJoin(): string
    {
        return 'INNER JOIN nexa_tenant_service ts ON ts.tenant_id = t.id AND ts.status = :tenantServiceStatus ' .
            'INNER JOIN nexa_service_definition sd ON sd.id = ts.service_id ' .
            'AND sd.service_key = :serviceKey AND sd.status = :serviceStatus ';
    }

    /** @param array{id: string, slug: string, display_name: string, service_id: string} $row */
    private function contextFromRow(array $row, string $source): TenantContext
    {
        return new TenantContext(
            $row['id'],
            $row['slug'],
            $source,
            $row['display_name'],
            $row['service_id'],
        );
    }
}
