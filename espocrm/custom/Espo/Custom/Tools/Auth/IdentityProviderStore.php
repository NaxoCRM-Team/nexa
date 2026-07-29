<?php

namespace Espo\Custom\Tools\Auth;

use Espo\ORM\EntityManager;
use PDO;
use RuntimeException;

/** Reads tenant-owned provider policy without exposing encrypted credentials. */
final class IdentityProviderStore
{
    public function __construct(
        private EntityManager $entityManager,
        private ProviderSecretCipher $secretCipher,
    ) {}

    /** @return array<string, mixed> */
    public function getEnabled(string $providerId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT p.*, t.slug AS tenant_slug, t.display_name AS tenant_name, '
            . 'COALESCE(a.allow_password_login, 1) AS allow_password_login, '
            . 'COALESCE(a.require_mfa, 0) AS tenant_require_mfa, '
            . 'COALESCE(a.allow_self_service_linking, 0) AS allow_self_service_linking '
            . 'FROM nexa_identity_provider p '
            . 'JOIN nexa_tenant t ON t.id = p.tenant_id AND t.status = \'active\' '
            . 'LEFT JOIN nexa_tenant_auth_policy a ON a.tenant_id = p.tenant_id '
            . 'WHERE p.id = ? AND p.is_enabled = 1'
        );
        $statement->execute([$providerId]);
        $provider = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$provider) {
            throw new RuntimeException('Identity provider is unavailable.');
        }

        $provider['client_secret'] = $this->secretCipher->decrypt($provider['encrypted_client_secret']);
        $provider['allowed_email_domains'] = $this->jsonList($provider['allowed_email_domains']);
        $provider['attribute_mapping'] = $this->jsonMap($provider['attribute_mapping']);

        return $provider;
    }

    /**
     * Returns the same provider choices for every address in a configured domain.
     * This avoids disclosing whether an individual account already exists.
     *
     * @return list<array{id:string,key:string,label:string,protocol:string,startUrl:string}>
     */
    public function discover(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [];
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        $statement = $this->pdo()->prepare(
            'SELECT p.id,p.provider_key,p.display_name,p.protocol '
            . 'FROM nexa_identity_provider p JOIN nexa_identity_provider_domain d ON d.provider_id=p.id AND d.tenant_id=p.tenant_id '
            . 'WHERE p.is_enabled=1 AND d.domain_name=? ORDER BY p.display_name'
        );
        $statement->execute([$domain]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['id'],
                'key' => $row['provider_key'],
                'label' => $row['display_name'],
                'protocol' => $row['protocol'],
                'startUrl' => 'api/v1/Nexa/auth/sso/' . rawurlencode($row['id']) . '/start',
            ];
        }

        return $result;
    }

    private function pdo(): PDO
    {
        return $this->entityManager->getPDO();
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded)
            ? array_values(array_filter(array_map(
                static fn (mixed $item): string => strtolower(trim((string) $item)),
                $decoded,
            )))
            : [];
    }

    /** @return array<string, string> */
    private function jsonMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
