<?php

namespace Espo\Custom\Tools\Auth;

use DateTimeImmutable;
use Espo\Core\Authentication\AuthToken\Data as AuthTokenData;
use Espo\Core\Authentication\AuthToken\Manager as AuthTokenManager;
use Espo\Core\Authentication\Jwt\Token;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Signup\SignupService;
use Espo\ORM\EntityManager;
use OneLogin\Saml2\Auth as SamlAuth;
use PDO;
use RuntimeException;
use Throwable;

/** Executes tenant-owned OIDC and SAML flows without email-based auto-linking. */
final class EnterpriseSsoService
{
    private const CRM_SERVICE_ID = '20000000-0000-4000-8000-000000000001';

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private IdentityProviderStore $providerStore,
        private GenericOidcTokenValidator $oidcValidator,
        private AuthTokenManager $authTokenManager,
        private TenantContextStore $tenantContextStore,
        private SignupService $signupService,
    ) {
        $autoload = dirname(__DIR__, 6) . '/packages/sso/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /** @param array<string, mixed> $query */
    public function start(string $providerId, array $query): string
    {
        $provider = $this->providerStore->getEnabled($providerId);
        $intent = ($query['intent'] ?? 'login') === 'signup' ? 'signup' : 'login';
        if ($intent === 'signup' && !(bool) $provider['allow_signup']) {
            throw new RuntimeException('This provider does not allow self-service signup.');
        }
        $payload = ['providerId' => $providerId, 'intent' => $intent];
        if ($intent === 'signup') {
            $payload['plan'] = $this->plan((string) ($query['plan'] ?? 'growth'));
        }

        return $provider['protocol'] === 'saml'
            ? $this->startSaml($provider, $payload)
            : $this->startOidc($provider, $payload);
    }

    public function oidcCallback(string $providerId, string $state, string $code): string
    {
        try {
            $attempt = $this->consumeAttempt('oidc:' . $providerId, $state);
            $provider = $this->providerStore->getEnabled($providerId);
            if ($provider['protocol'] !== 'oidc' || $code === '') {
                throw new RuntimeException('OIDC callback is invalid.');
            }
            $token = Token::create($this->exchangeOidcCode($provider, $code));
            $profile = $this->oidcValidator->validate($token, $provider, $attempt['nonce_hash']);

            return $this->complete($provider, $attempt, $profile);
        } catch (Throwable $e) {
            $this->auditFailure($providerId, 'auth.oidc.failed', $e);

            return $this->failureUrl('enterprise_sso_failed');
        }
    }

    public function samlCallback(string $providerId, string $relayState, string $response): string
    {
        try {
            $attempt = $this->consumeAttempt('saml:' . $providerId, $relayState);
            $provider = $this->providerStore->getEnabled($providerId);
            if ($provider['protocol'] !== 'saml' || $response === '') {
                throw new RuntimeException('SAML callback is invalid.');
            }
            $auth = new SamlAuth($this->samlSettings($provider));
            $previous = $_POST;
            $_POST = ['SAMLResponse' => $response, 'RelayState' => $relayState];
            try {
                $auth->processResponse((string) ($attempt['payload']['requestId'] ?? ''));
            } finally {
                $_POST = $previous;
            }
            if (!$auth->isAuthenticated() || $auth->getErrors() !== []) {
                throw new RuntimeException($auth->getLastErrorReason() ?: 'SAML assertion validation failed.');
            }
            $attributes = $auth->getAttributes();
            $mapping = (array) $provider['attribute_mapping'];
            $email = strtolower(trim((string) $this->attribute($attributes, $mapping['email'] ?? 'email')));
            $subject = trim((string) $auth->getNameId());
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '') {
                throw new RuntimeException('SAML provider did not return a usable identity.');
            }
            $profile = [
                'subject' => $subject,
                'email' => $email,
                'firstName' => $this->attribute($attributes, $mapping['firstName'] ?? 'firstName'),
                'lastName' => $this->attribute($attributes, $mapping['lastName'] ?? 'lastName'),
                'picture' => '',
            ];

            return $this->complete($provider, $attempt, $profile);
        } catch (Throwable $e) {
            $this->auditFailure($providerId, 'auth.saml.failed', $e);

            return $this->failureUrl('enterprise_sso_failed');
        }
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $payload */
    private function startOidc(array $provider, array $payload): string
    {
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_endpoint'] as $field) {
            if (!str_starts_with((string) $provider[$field], 'https://')) {
                throw new RuntimeException('OIDC endpoints must use HTTPS.');
            }
        }
        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $this->storeAttempt('oidc:' . $provider['id'], $state, $nonce, $payload);

        return $provider['authorization_endpoint'] . '?' . http_build_query([
            'client_id' => $provider['client_id'],
            'redirect_uri' => $this->oidcCallbackUrl($provider['id']),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ]);
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $payload */
    private function startSaml(array $provider, array $payload): string
    {
        $relayState = $this->randomToken();
        $auth = new SamlAuth($this->samlSettings($provider));
        $url = $auth->login($relayState, [], false, false, true);
        $payload['requestId'] = $auth->getLastRequestID();
        $this->storeAttempt('saml:' . $provider['id'], $relayState, $this->randomToken(), $payload);

        return $url;
    }

    /** @param array<string,mixed> $provider @return array<string,mixed> */
    private function samlSettings(array $provider): array
    {
        if (!str_starts_with((string) $provider['saml_sso_url'], 'https://')) {
            throw new RuntimeException('SAML SSO URL must use HTTPS.');
        }

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->siteUrl() . '/saml/metadata/' . $provider['id'],
                'assertionConsumerService' => [
                    'url' => $this->samlCallbackUrl($provider['id']),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ],
            'idp' => [
                'entityId' => $provider['issuer'],
                'singleSignOnService' => [
                    'url' => $provider['saml_sso_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $provider['saml_x509_certificate'],
            ],
            'security' => [
                'authnRequestsSigned' => false,
                'wantMessagesSigned' => false,
                'wantAssertionsSigned' => true,
                'wantNameId' => true,
                'wantAttributeStatement' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'requestedAuthnContext' => (bool) $provider['require_mfa']
                    ? [$provider['attribute_mapping']['mfaContext'] ?? 'urn:oasis:names:tc:SAML:2.0:ac:classes:TimeSyncToken']
                    : false,
                'allowRepeatAttributeName' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $attempt @param array<string,string> $profile */
    private function complete(array $provider, array $attempt, array $profile): string
    {
        $this->assertDomain($provider, $profile['email']);
        $identityKey = 'tenant:' . $provider['id'];
        $identity = $this->findIdentity($provider['tenant_id'], $identityKey, $profile['subject']);
        if ($identity !== null) {
            return $this->sessionUrl($identity, $provider['protocol']);
        }
        if ($attempt['intent'] !== 'signup' || !(bool) $provider['allow_signup']) {
            // A matching email is deliberately insufficient. Linking requires a
            // separately authenticated approval and is never done in a callback.
            return $this->failureUrl('social_account_not_linked');
        }
        $signup = $this->signupService->beginSocial(
            $identityKey,
            $profile,
            (string) ($attempt['payload']['plan'] ?? 'growth'),
        );

        return $this->completionUrl((string) $signup['attemptToken'], (string) ($attempt['payload']['plan'] ?? 'growth'));
    }

    /** @return array<string,mixed> */
    private function consumeAttempt(string $provider, string $state): array
    {
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM nexa_social_auth_attempt WHERE provider=? AND state_hash=? FOR UPDATE');
            $statement->execute([$provider, hash('sha256', $state)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['consumed_at'] !== null || new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
                throw new RuntimeException('Federated authentication state is invalid, replayed or expired.');
            }
            $pdo->prepare('UPDATE nexa_social_auth_attempt SET consumed_at=CURRENT_TIMESTAMP(6) WHERE id=?')->execute([$row['id']]);
            $pdo->commit();
            $payload = (array) json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (($payload['providerId'] ?? null) !== substr($provider, strpos($provider, ':') + 1)) {
                throw new RuntimeException('Federated authentication provider mismatch.');
            }

            return [
                'intent' => (string) $row['intent'],
                'nonce_hash' => (string) $row['nonce_hash'],
                'payload' => $payload,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $payload */
    private function storeAttempt(string $provider, string $state, string $nonce, array $payload): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_social_auth_attempt (id,provider,intent,state_hash,nonce_hash,payload_json,expires_at) '
            . 'VALUES (?,?,?,?,?,?,DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 10 MINUTE))'
        );
        $statement->execute([
            $this->uuid(), $provider, $payload['intent'], hash('sha256', $state), hash('sha256', $nonce),
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string,mixed> $provider */
    private function exchangeOidcCode(array $provider, string $code): string
    {
        $curl = curl_init((string) $provider['token_endpoint']);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $provider['client_id'],
                'client_secret' => $provider['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->oidcCallbackUrl($provider['id']),
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($error !== '' || $status !== 200 || !is_string($data['id_token'] ?? null)) {
            throw new RuntimeException('OIDC token exchange failed.');
        }

        return $data['id_token'];
    }

    /** @return array<string,string>|null */
    private function findIdentity(string $tenantId, string $provider, string $subject): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT i.tenant_id,i.user_id,u.user_name,t.slug,t.display_name FROM nexa_external_identity i '
            . 'JOIN nexa_tenant t ON t.id=i.tenant_id JOIN `user` u ON u.id=i.user_id AND u.tenant_id=i.tenant_id '
            . 'WHERE i.tenant_id=? AND i.provider=? AND i.provider_subject=? AND t.status=\'active\' AND u.is_active=1 AND u.deleted=0'
        );
        $statement->execute([$tenantId, $provider, $subject]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string,string> $identity */
    private function sessionUrl(array $identity, string $protocol): string
    {
        $context = new TenantContext($identity['tenant_id'], $identity['slug'], $protocol . '-sso', $identity['display_name']);
        $token = $this->tenantContextStore->runWith($context, fn () => $this->authTokenManager->create(
            AuthTokenData::create(['userId' => $identity['user_id']]),
        ));
        $this->audit($identity['tenant_id'], $identity['user_id'], 'auth.' . $protocol . '.login', $protocol . '-sso');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'userName' => $identity['user_name'], 'token' => $token->getToken(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $this->siteUrl() . '/login#nexa-social=' . $payload;
    }

    /** @param array<string,mixed> $provider */
    private function assertDomain(array $provider, string $email): void
    {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        if (!in_array($domain, $provider['allowed_email_domains'], true)) {
            throw new RuntimeException('Identity email domain is not allowed by tenant policy.');
        }
    }

    private function auditFailure(string $providerId, string $action, Throwable $error): void
    {
        try {
            $provider = $this->providerStore->getEnabled($providerId);
            $this->audit((string) $provider['tenant_id'], null, $action, (string) $provider['protocol'], [
                'reason' => substr(hash('sha256', $error->getMessage()), 0, 16),
            ]);
        } catch (Throwable) {
            // Failure reporting must never make a neutral authentication response fail open.
        }
    }

    /** @param array<string,string> $metadata */
    private function audit(string $tenantId, ?string $userId, string $action, string $source, array $metadata = []): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,source,metadata_json) '
            . 'VALUES (?,?,?,\'user\',?,?,\'identity-provider\',?,?,?)'
        );
        $statement->execute([
            $this->uuid(), $tenantId, self::CRM_SERVICE_ID, $userId, $action, $userId, $source,
            json_encode($metadata ?: (object) [], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string,list<string>> $attributes */
    private function attribute(array $attributes, string $name): string
    {
        $value = $attributes[$name][0] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function plan(string $plan): string
    {
        if (!in_array($plan, ['launch', 'growth', 'scale'], true)) {
            throw new RuntimeException('The selected plan is unavailable.');
        }

        return $plan;
    }

    private function oidcCallbackUrl(string $id): string { return $this->siteUrl() . '/api/v1/Nexa/auth/sso/' . rawurlencode($id) . '/oidc/callback'; }
    private function samlCallbackUrl(string $id): string { return $this->siteUrl() . '/api/v1/Nexa/auth/sso/' . rawurlencode($id) . '/saml/acs'; }
    public function failureRedirectUrl(string $reason): string { return $this->failureUrl($reason); }

    private function failureUrl(string $reason): string { return $this->siteUrl() . '/login?socialError=' . rawurlencode($reason); }
    private function completionUrl(string $token, string $plan): string { return $this->siteUrl() . '/?signup=complete&plan=' . rawurlencode($plan) . '#nexa-onboarding=' . rtrim(strtr(base64_encode($token), '+/', '-_'), '='); }
    private function siteUrl(): string { return rtrim((string) $this->config->get('siteUrl'), '/'); }
    private function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }
}
