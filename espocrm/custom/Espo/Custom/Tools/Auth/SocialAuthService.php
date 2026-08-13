<?php

namespace Espo\Custom\Tools\Auth;

use DateTimeImmutable;
use Espo\Core\Authentication\AuthToken\Data as AuthTokenData;
use Espo\Core\Authentication\AuthToken\Manager as AuthTokenManager;
use Espo\Core\Authentication\Jwt\Token;
use Espo\Core\Authentication\Jwt\Validator;
use Espo\Core\Authentication\Oidc\TokenValidator;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Signup\SignupService;
use Espo\ORM\EntityManager;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Completes Google OIDC without trusting provider email as an account-link key.
 * Only a stored provider subject may sign in to an existing Nexa user.
 */
final class SocialAuthService
{
    private const CRM_SERVICE_ID = '20000000-0000-4000-8000-000000000001';

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private Validator $jwtValidator,
        private TokenValidator $tokenValidator,
        private AuthTokenManager $authTokenManager,
        private TenantContextStore $tenantContextStore,
        private AuthProviderRegistry $providerRegistry,
        private SignupService $signupService,
        private MicrosoftIdTokenValidator $microsoftTokenValidator,
        private Log $log,
    ) {}

    /** @param array<string, string|array<scalar, mixed>> $query */
    public function start(string $provider, array $query): string
    {
        $this->assertProvider($provider);
        $intent = ($query['intent'] ?? 'login') === 'signup' ? 'signup' : 'login';
        $payload = ['intent' => $intent];

        if ($intent === 'signup') {
            $plan = trim((string) ($query['plan'] ?? 'growth'));
            if (!in_array($plan, ['launch', 'growth', 'scale'], true)) {
                throw new RuntimeException('The selected plan is unavailable.');
            }
            $payload['plan'] = $plan;
        }

        $state = $this->randomUrlToken();
        $nonce = $this->randomUrlToken();
        $pdo = $this->entityManager->getPDO();
        $statement = $pdo->prepare(
            'INSERT INTO nexa_social_auth_attempt (id, provider, intent, state_hash, nonce_hash, payload_json, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL 10 MINUTE))'
        );
        $statement->execute([
            $this->uuid(), $provider, $intent, hash('sha256', $state), hash('sha256', $nonce),
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $this->authorizationEndpoint($provider) . '?' . http_build_query([
            'client_id' => $this->clientId($provider),
            'redirect_uri' => $this->callbackUrl($provider),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ]);
    }

    public function callback(string $provider, string $state, string $code): string
    {
        $this->assertProvider($provider);
        if ($state === '' || $code === '') {
            return $this->failureUrl('provider_cancelled', provider: $provider);
        }

        $attempt = null;

        try {
            $attempt = $this->consumeAttempt($provider, $state);
            $rawToken = $this->exchangeCode($code, $provider);
            $token = Token::create($rawToken);

            if ($provider === 'microsoft') {
                $profile = $this->microsoftTokenValidator->validate(
                    $token,
                    $this->clientId($provider),
                    $this->microsoftTenant(),
                    $attempt['nonce_hash'],
                );
            } else {
                $this->jwtValidator->validate($token);
                $this->tokenValidator->validateFields($token);
                $this->tokenValidator->validateSignature($token);

                $claims = $token->getPayload();
                if (!in_array($claims->getIss(), ['https://accounts.google.com', 'accounts.google.com'], true) ||
                    !hash_equals($attempt['nonce_hash'], hash('sha256', (string) $claims->getNonce())) ||
                    $claims->get('email_verified') !== true) {
                    throw new RuntimeException('Google identity validation failed.');
                }

                $profile = [
                    'subject' => (string) $claims->getSub(),
                    'email' => strtolower(trim((string) $claims->get('email'))),
                    'firstName' => trim((string) $claims->get('given_name')),
                    'lastName' => trim((string) $claims->get('family_name')),
                    'picture' => trim((string) $claims->get('picture')),
                ];
            }            if (!filter_var($profile['email'], FILTER_VALIDATE_EMAIL) || $profile['subject'] === '') {
                throw new RuntimeException('Google did not return a verified email identity.');
            }

            if ($attempt['intent'] === 'signup') {
                $identity = $this->findIdentity($provider, $profile['subject']);

                // Existing linked identities sign in. New identities resume
                // workspace onboarding before tenant records are provisioned.
                if ($identity !== null) {
                    return $this->sessionUrl($provider, $identity);
                }

                $signup = $this->signupService->beginSocial(
                    $provider,
                    $profile,
                    (string) ($attempt['payload']['plan'] ?? 'growth'),
                );

                return $this->completionUrl(
                    (string) $signup['attemptToken'],
                    (string) ($attempt['payload']['plan'] ?? 'growth'),
                );
            }

            $identity = $this->findIdentity($provider, $profile['subject']);

            if ($identity === null) {
                return $this->failureUrl('social_account_not_linked', provider: $provider);
            }

            return $this->sessionUrl($provider, $identity);
        } catch (Throwable $e) {
            // OAuth errors must be diagnosable without exposing provider
            // tokens, authorization codes or account details to the browser.
            $this->log->warning(
                'Nexa social authentication failed for provider {provider}: {message}',
                ['provider' => $provider, 'message' => $e->getMessage(), 'exception' => $e]
            );
            $intent = is_array($attempt) ? (string) ($attempt['intent'] ?? 'login') : 'login';
            $plan = is_array($attempt) ? (string) ($attempt['payload']['plan'] ?? 'growth') : 'growth';

            return $this->failureUrl('social_auth_failed', $intent, $plan, $provider);
        }
    }

    /** @return array{intent:string,nonce_hash:string,payload:array<string,mixed>} */
    private function consumeAttempt(string $provider, string $state): array
    {
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT * FROM nexa_social_auth_attempt WHERE provider = ? AND state_hash = ? FOR UPDATE'
            );
            $statement->execute([$provider, hash('sha256', $state)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['consumed_at'] !== null || new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
                throw new RuntimeException('OAuth state is invalid or expired.');
            }
            $pdo->prepare('UPDATE nexa_social_auth_attempt SET consumed_at = CURRENT_TIMESTAMP(6) WHERE id = ?')
                ->execute([$row['id']]);
            $pdo->commit();
            return [
                'intent' => $row['intent'],
                'nonce_hash' => $row['nonce_hash'],
                'payload' => (array) json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function exchangeCode(string $code, string $provider): string
    {
        $curl = curl_init($this->tokenEndpoint($provider));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId($provider),
                'client_secret' => $this->clientSecret($provider),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->callbackUrl($provider),
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($error !== '' || $status !== 200 || !is_array($data) || !is_string($data['id_token'] ?? null)) {
            throw new RuntimeException(
                ucfirst($provider) . ' token exchange failed: ' . ($error ?: 'provider rejected the request')
            );
        }
        return $data['id_token'];
    }

    /** @return array<string,string>|null */
    private function findIdentity(string $provider, string $subject): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT i.tenant_id, i.user_id, u.user_name, t.slug, t.display_name FROM nexa_external_identity i '
            . 'JOIN nexa_tenant t ON t.id = i.tenant_id JOIN `user` u ON u.id = i.user_id AND u.tenant_id = i.tenant_id '
            . 'WHERE i.provider = ? AND i.provider_subject = ? AND t.status = \'active\' AND u.is_active = 1 AND u.deleted = 0'
        );
        $statement->execute([$provider, $subject]);
        $identity = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$identity) return null;
        $this->entityManager->getPDO()->prepare('UPDATE nexa_external_identity SET last_login_at = CURRENT_TIMESTAMP(6) WHERE provider = ? AND provider_subject = ?')->execute([$provider, $subject]);
        return $identity;
    }

    /** @param array<string,string> $identity */
    private function sessionUrl(string $provider, array $identity): string
    {
        $context = new TenantContext(
            $identity['tenant_id'],
            $identity['slug'],
            $provider . '-social-auth',
            $identity['display_name']
        );
        $token = $this->tenantContextStore->runWith($context, fn () => $this->authTokenManager->create(
            AuthTokenData::create(['userId' => $identity['user_id']])
        ));
        $this->sql(
            $this->entityManager->getPDO(),
            'INSERT INTO nexa_audit_event (id, tenant_id, service_id, actor_type, actor_user_id, action, subject_type, subject_id, source, metadata_json) VALUES (?, ?, ?, \'user\', ?, \'auth.social.login\', \'user\', ?, ?, JSON_OBJECT())',
            [
                $this->uuid(),
                $identity['tenant_id'],
                self::CRM_SERVICE_ID,
                $identity['user_id'],
                $identity['user_id'],
                $provider . '-oidc',
            ]
        );
        $payload = rtrim(strtr(base64_encode(json_encode([
            'userName' => $identity['user_name'],
            'token' => $token->getToken(),
            'provider' => $provider,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return rtrim((string) $this->config->get('siteUrl'), '/') . '/login#nexa-social=' . $payload;
    }

    public function failureRedirectUrl(string $reason, string $provider = ''): string
    {
        return $this->failureUrl($reason, provider: $provider);
    }

    public function callbackCookiePath(string $provider): string
    {
        return (string) (parse_url($this->callbackUrl($provider), PHP_URL_PATH) ?: '/');
    }

    private function failureUrl(
        string $reason,
        string $intent = 'login',
        string $plan = 'growth',
        string $provider = '',
    ): string
    {
        $providerParameter = $provider !== ''
            ? '&socialProvider=' . rawurlencode($provider)
            : '';

        // Keep failed self-service signups in onboarding. Sending them to the
        // login page falsely suggests that a workspace account was created.
        if ($intent === 'signup') {
            return rtrim((string) $this->config->get('siteUrl'), '/')
                . '/?signup=' . rawurlencode($plan)
                . '&socialError=' . rawurlencode($reason)
                . $providerParameter;
        }

        return rtrim((string) $this->config->get('siteUrl'), '/')
            . '/login?socialError=' . rawurlencode($reason)
            . $providerParameter;
    }

    private function completionUrl(string $attemptToken, string $plan): string
    {
        $payload = rtrim(strtr(base64_encode($attemptToken), '+/', '-_'), '=');

        return rtrim((string) $this->config->get('siteUrl'), '/')
            . '/?signup=complete&plan=' . rawurlencode($plan)
            . '#nexa-onboarding=' . $payload;
    }

    private function assertProvider(string $provider): void
    {
        $enabled = array_column($this->providerRegistry->getPublicProviders(), 'key');
        if (!in_array($provider, $enabled, true)) {
            throw new RuntimeException('This identity provider is not configured.');
        }
    }

    private function callbackUrl(string $provider): string
    {
        $key = 'NEXA_AUTH_' . strtoupper($provider) . '_REDIRECT_URI';
        $configured = trim((string) (getenv($key) ?: ($provider === 'google' ? $this->config->get('nexaGoogleRedirectUri', '') : $this->config->get('nexaMicrosoftRedirectUri', ''))));
        return $configured !== ''
            ? $configured
            : rtrim((string) $this->config->get('siteUrl'), '/') . '/api/v1/Nexa/auth/provider/' . $provider . '/callback';
    }

    private function clientId(string $provider): string
    {
        $key = 'NEXA_AUTH_' . strtoupper($provider) . '_CLIENT_ID';
        return trim((string) (getenv($key) ?: ($provider === 'google' ? $this->config->get('oidcClientId', '') : $this->config->get('nexaMicrosoftClientId', ''))));
    }

    private function clientSecret(string $provider): string
    {
        $key = 'NEXA_AUTH_' . strtoupper($provider) . '_CLIENT_SECRET';
        return trim((string) (getenv($key) ?: ($provider === 'google' ? $this->config->get('oidcClientSecret', '') : $this->config->get('nexaMicrosoftClientSecret', ''))));
    }

    private function microsoftTenant(): string
    {
        return trim((string) (getenv('NEXA_AUTH_MICROSOFT_TENANT_ID') ?: $this->config->get('nexaMicrosoftTenantId', 'common')));
    }

    private function authorizationEndpoint(string $provider): string
    {
        return $provider === 'microsoft'
            ? 'https://login.microsoftonline.com/' . rawurlencode($this->microsoftTenant()) . '/oauth2/v2.0/authorize'
            : 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    private function tokenEndpoint(string $provider): string
    {
        return $provider === 'microsoft'
            ? 'https://login.microsoftonline.com/' . rawurlencode($this->microsoftTenant()) . '/oauth2/v2.0/token'
            : 'https://oauth2.googleapis.com/token';
    }
    private function randomUrlToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function entityId(): string { return substr(bin2hex(random_bytes(9)), 0, 17); }
    private function slug(string $company): string { return substr(strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $company), '-')) ?: 'workspace', 0, 54) . '-' . bin2hex(random_bytes(4)); }
    private function uuid(): string { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
    /** @param list<mixed> $params */
    private function sql(PDO $pdo, string $sql, array $params): void { $statement = $pdo->prepare($sql); $statement->execute($params); }
}
