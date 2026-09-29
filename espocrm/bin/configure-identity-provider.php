<?php

declare(strict_types=1);

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

require dirname(__DIR__) . '/bootstrap.php';

/** @return array<string,string> */
function arguments(array $values): array
{
    $result = [];
    foreach (array_slice($values, 1) as $value) {
        if (str_starts_with($value, '--') && str_contains($value, '=')) {
            [$name, $content] = explode('=', substr($value, 2), 2);
            $result[$name] = trim($content);
        }
    }

    return $result;
}

function required(array $options, string $name): string
{
    $value = trim((string) ($options[$name] ?? ''));
    if ($value === '') {
        throw new RuntimeException("Missing required --{$name}= value.");
    }

    return $value;
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

try {
    $options = arguments($argv);
    $protocol = strtolower(required($options, 'protocol'));
    if (!in_array($protocol, ['oidc', 'saml'], true)) {
        throw new RuntimeException('--protocol must be oidc or saml.');
    }
    $application = new Application();
    $container = $application->getContainer();
    $entityManager = $container->getByClass(EntityManager::class);
    $cipher = $container->getByClass(InjectableFactory::class)->create(ProviderSecretCipher::class);
    $pdo = $entityManager->getPDO();
    $tenant = $pdo->prepare('SELECT id FROM nexa_tenant WHERE slug=? AND status=\'active\'');
    $tenant->execute([required($options, 'tenant')]);
    $tenantId = $tenant->fetchColumn();
    if (!$tenantId) {
        throw new RuntimeException('Active tenant was not found.');
    }
    $domains = array_values(array_filter(array_map(
        static fn (string $domain): string => strtolower(trim($domain)),
        explode(',', required($options, 'domains')),
    )));
    $certificate = '';
    if ($protocol === 'saml') {
        $certificatePath = required($options, 'certificate-file');
        $certificate = is_file($certificatePath) ? trim((string) file_get_contents($certificatePath)) : '';
        if (!str_contains($certificate, 'BEGIN CERTIFICATE')) {
            throw new RuntimeException('A valid SAML X.509 certificate file is required.');
        }
    }
    $secret = $protocol === 'oidc' ? required($options, 'client-secret') : '';
    $id = $options['id'] ?? uuid();
    $values = [
        'id' => $id,
        'tenant_id' => $tenantId,
        'provider_key' => required($options, 'key'),
        'protocol' => $protocol,
        'display_name' => required($options, 'name'),
        'issuer' => required($options, 'issuer'),
        'client_id' => $options['client-id'] ?? null,
        'encrypted_client_secret' => $secret !== '' ? $cipher->encryptFor(
            $secret,
            $tenantId,
            \Espo\Core\Tenant\TenantContext::CRM_SERVICE_ID,
            'identity-provider:' . $id,
        ) : null,
        'secret_key_version' => $secret !== '' ? 1 : null,
        'authorization_endpoint' => $options['authorization-endpoint'] ?? null,
        'token_endpoint' => $options['token-endpoint'] ?? null,
        'userinfo_endpoint' => $options['userinfo-endpoint'] ?? null,
        'jwks_endpoint' => $options['jwks-endpoint'] ?? null,
        'saml_sso_url' => $options['sso-url'] ?? null,
        'saml_x509_certificate' => $certificate ?: null,
        'allowed_email_domains' => json_encode($domains, JSON_THROW_ON_ERROR),
        'attribute_mapping' => json_encode([
            'email' => $options['email-claim'] ?? 'email',
            'firstName' => $options['first-name-claim'] ?? 'given_name',
            'lastName' => $options['last-name-claim'] ?? 'family_name',
            'mfaContext' => $options['mfa-context'] ?? 'urn:oasis:names:tc:SAML:2.0:ac:classes:TimeSyncToken',
        ], JSON_THROW_ON_ERROR),
        'is_enabled' => ($options['enabled'] ?? 'true') === 'true' ? 1 : 0,
        'allow_signup' => ($options['allow-signup'] ?? 'false') === 'true' ? 1 : 0,
        'require_mfa' => ($options['require-mfa'] ?? 'true') === 'true' ? 1 : 0,
    ];
    $columns = array_keys($values);
    $updates = array_filter($columns, static fn (string $column): bool => !in_array($column, ['id', 'tenant_id'], true));
    $sql = 'INSERT INTO nexa_identity_provider (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_map(static fn (string $column): string => ':' . $column, $columns)) . ') '
        . 'ON DUPLICATE KEY UPDATE ' . implode(',', array_map(
            static fn (string $column): string => "`{$column}`=VALUES(`{$column}`)",
            $updates,
        ));
    $pdo->beginTransaction();
    $pdo->prepare($sql)->execute($values);
    $pdo->prepare('DELETE FROM nexa_identity_provider_domain WHERE provider_id=?')->execute([$id]);
    $domainStatement = $pdo->prepare(
        'INSERT INTO nexa_identity_provider_domain (domain_name,tenant_id,provider_id) VALUES (?,?,?)'
    );
    foreach ($domains as $domain) {
        $domainStatement->execute([$domain, $tenantId, $id]);
    }
    $pdo->prepare(
        'INSERT INTO nexa_tenant_auth_policy (tenant_id,require_mfa) VALUES (?,?) '
        . 'ON DUPLICATE KEY UPDATE require_mfa=VALUES(require_mfa)'
    )->execute([$tenantId, $values['require_mfa']]);
    $pdo->commit();
    fwrite(STDOUT, "Configured {$protocol} provider {$id} for tenant {$options['tenant']}." . PHP_EOL);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
