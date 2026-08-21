<?php

namespace Espo\Custom\Tools\Mail;

use DateTimeImmutable;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\EmailAccount;
use Espo\Entities\User;
use PDO;
use RuntimeException;
use Throwable;

/**
 * "Connect your inbox" OAuth (Gmail/Microsoft), kept deliberately separate
 * from Tools/Auth/SocialAuthService: that flow only ever exchanges a
 * short-lived id_token to identify a user for login and never requests a
 * refresh token. Sending mail indefinitely needs an offline-access refresh
 * token and persistent storage, which is what this class adds - built as a
 * parallel flow, not a reuse, but following the same shape (state-row CSRF,
 * env-first config, cookie-free) established there.
 *
 * start()/finish() run under normal, authenticated tenant-scoped requests
 * (a live TenantContext is available). handleCallback() is hit directly by
 * Google/Microsoft with no EspoCRM session - it runs under
 * PlatformExecutionGateway (confirmed empirically: ORM createEntity() cannot
 * resolve a tenant there and the NOT NULL tenant_id column rejects the
 * insert), so it only ever writes the pending result via raw SQL onto the
 * nexa_mailbox_oauth_attempt row it already owns; finish() - back under a
 * live authenticated request - is what actually creates the EmailAccount.
 */
final class MailOAuthService
{
    private const CRM_SERVICE_ID = '20000000-0000-4000-8000-000000000001';
    private const ATTEMPT_TTL_MINUTES = 10;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private ProviderSecretCipher $cipher,
        private Log $log,
        private User $user,
    ) {}

    /** @return list<array{key:string,label:string}> */
    public function getConfiguredProviders(): array
    {
        $providers = [];
        foreach (['google', 'microsoft'] as $provider) {
            if ($this->isConfigured($provider)) {
                $providers[] = ['key' => $provider, 'label' => $provider === 'google' ? 'Google' : 'Microsoft'];
            }
        }
        return $providers;
    }

    public function isConfigured(string $provider): bool
    {
        $this->assertProvider($provider);
        return $this->clientId($provider) !== '' && $this->clientSecret($provider) !== '' && $this->redirectUri($provider) !== '';
    }

    public function start(string $provider): string
    {
        $this->assertProvider($provider);
        if (!$this->isConfigured($provider)) {
            throw new RuntimeException(ucfirst($provider) . ' inbox connection is not configured.');
        }

        $tenant = $this->tenantContextStore->require();
        $state = $this->randomUrlToken();
        $pdo = $this->entityManager->getPDO();
        $statement = $pdo->prepare(
            'INSERT INTO nexa_mailbox_oauth_attempt (id, tenant_id, user_id, provider, state_hash, expires_at) ' .
            'VALUES (?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ' . self::ATTEMPT_TTL_MINUTES . ' MINUTE))'
        );
        $statement->execute([$this->uuid(), $tenant->tenantId, $this->user->getId(), $provider, hash('sha256', $state)]);

        $params = [
            'client_id' => $this->clientId($provider),
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => $this->scope($provider),
            'state' => $state,
            'access_type' => 'offline',
        ];
        // Google only returns a refresh token on the FIRST consent unless
        // forced - a user reconnecting after a revoke would otherwise get no
        // refresh token at all and silently stop being able to send once
        // the short-lived access token expires.
        if ($provider === 'google') {
            $params['prompt'] = 'consent';
        }

        return $this->authorizationEndpoint($provider) . '?' . http_build_query($params);
    }

    /** Writes the pending result onto the attempt row via raw SQL only - see class docblock. */
    public function handleCallback(string $provider, string $state, ?string $code, ?string $errorParam): void
    {
        $this->assertProvider($provider);
        $pdo = $this->entityManager->getPDO();

        $attemptId = $this->lockPendingAttempt($pdo, $provider, $state);
        if ($attemptId === null) {
            return;
        }

        if ($errorParam !== null && $errorParam !== '') {
            $this->failAttempt($pdo, $attemptId, 'provider_denied');
            return;
        }
        if ($code === null || $code === '') {
            $this->failAttempt($pdo, $attemptId, 'missing_code');
            return;
        }

        try {
            $token = $this->exchangeCode($provider, $code);
            $email = $this->resolveEmailAddress($provider, $token);

            $this->log->info(
                'Nexa mailbox OAuth grant for provider {provider}: requested="{requested}" granted="{granted}"',
                ['provider' => $provider, 'requested' => $this->scope($provider), 'granted' => $token['grantedScope']]
            );

            if (!$this->grantIncludesRequiredScope($provider, $token['grantedScope'])) {
                $this->failAttempt($pdo, $attemptId, 'scope_not_granted');
                return;
            }

            $pdo->prepare(
                'UPDATE nexa_mailbox_oauth_attempt SET status = \'ready\', email_address = ?, ' .
                'access_token_encrypted = ?, refresh_token_encrypted = ?, token_expires_at = ?, scopes = ? ' .
                'WHERE id = ?'
            )->execute([
                $email,
                $this->cipher->encrypt($token['accessToken']),
                $this->cipher->encrypt($token['refreshToken']),
                $token['expiresAt']->format('Y-m-d H:i:s.u'),
                $token['grantedScope'],
                $attemptId,
            ]);
        } catch (Throwable $e) {
            $this->log->warning(
                'Nexa mailbox OAuth failed for provider {provider}: {message}',
                ['provider' => $provider, 'message' => $e->getMessage(), 'exception' => $e]
            );
            $this->failAttempt($pdo, $attemptId, 'exchange_failed');
        }
    }

    /** @return array{emailAccountId:string,emailAddress:string} */
    public function finish(string $provider, string $state): array
    {
        $this->assertProvider($provider);
        $tenant = $this->tenantContextStore->require();
        $tenantId = $tenant->tenantId;
        $userId = $this->user->getId();
        $pdo = $this->entityManager->getPDO();

        $statement = $pdo->prepare(
            'SELECT * FROM nexa_mailbox_oauth_attempt WHERE provider = ? AND state_hash = ? ' .
            'AND tenant_id = ? AND user_id = ? AND consumed_at IS NULL LIMIT 1'
        );
        $statement->execute([$provider, hash('sha256', $state), $tenantId, $userId]);
        $attempt = $statement->fetch(PDO::FETCH_ASSOC);

        if ($attempt && $attempt['status'] === 'failed' && $attempt['failure_reason'] === 'scope_not_granted') {
            throw new RuntimeException(
                $provider === 'google'
                    ? 'Google did not grant full mail access. When reconnecting, make sure to approve the "Read, compose, send, and permanently delete all your email" permission - sending will not work without it.'
                    : 'Microsoft did not grant the permission needed to send mail. Please reconnect and approve access when prompted.'
            );
        }

        if (!$attempt || $attempt['status'] !== 'ready' || new DateTimeImmutable($attempt['expires_at']) < new DateTimeImmutable()) {
            throw new RuntimeException('This connection attempt is no longer valid. Please try connecting again.');
        }

        $pdo->prepare('UPDATE nexa_mailbox_oauth_attempt SET consumed_at = CURRENT_TIMESTAMP(6) WHERE id = ?')
            ->execute([$attempt['id']]);

        $tenantRowStatement = $pdo->prepare('SELECT slug, display_name FROM nexa_tenant WHERE id = ? LIMIT 1');
        $tenantRowStatement->execute([$tenantId]);
        $tenantRow = $tenantRowStatement->fetch(PDO::FETCH_ASSOC) ?: ['slug' => $tenantId, 'display_name' => ''];

        $context = new TenantContext(
            $tenantId,
            (string) $tenantRow['slug'],
            'mail-oauth-finish',
            (string) $tenantRow['display_name'],
            self::CRM_SERVICE_ID
        );

        return $this->tenantContextStore->runWith($context, function () use ($attempt, $provider, $userId, $tenantId) {
            $emailAddress = (string) $attempt['email_address'];
            $canFetch = $this->grantIncludesImapScope($provider, (string) $attempt['scopes']);

            $emailAccountData = [
                'name' => $emailAddress,
                'emailAddress' => $emailAddress,
                'assignedUserId' => $userId,
                'status' => 'Active',
                'useSmtp' => true,
                'smtpHost' => $this->smtpHost($provider, $emailAddress),
                'smtpPort' => 587,
                'smtpSecurity' => 'TLS',
                'smtpAuth' => true,
                'smtpUsername' => $emailAddress,
                'smtpHandler' => OAuthSmtpHandler::class,
                'useImap' => $canFetch,
            ];
            if ($canFetch) {
                $emailAccountData['host'] = $this->imapHost($provider, $emailAddress);
                $emailAccountData['port'] = 993;
                $emailAccountData['security'] = 'SSL';
                $emailAccountData['username'] = $emailAddress;
                $emailAccountData['imapHandler'] = OAuthImapHandler::class;
                // Required field with no metadata default (confirmed the
                // hard way - fetch fails outright without it). Today, not
                // further back: this feature is about catching replies
                // going forward, not importing mailbox history, matching
                // the explicit "not full inbox sync" scope of this feature.
                $emailAccountData['fetchSince'] = date('Y-m-d');
            }

            // Reconnecting the same address (e.g. to pick up a broader scope
            // granted after the first connection) must update in place, not
            // pile up a second EmailAccount for the same mailbox.
            $existingEmailAccount = $this->entityManager
                ->getRDBRepositoryByClass(EmailAccount::class)
                ->where([
                    'assignedUserId' => $userId,
                    'emailAddress' => $emailAddress,
                ])
                ->findOne();

            if ($existingEmailAccount) {
                $existingEmailAccount->set($emailAccountData);
                $this->entityManager->saveEntity($existingEmailAccount);
                $emailAccount = $existingEmailAccount;
            } else {
                $emailAccount = $this->entityManager->createEntity(EmailAccount::ENTITY_TYPE, $emailAccountData);
            }

            $pdo = $this->entityManager->getPDO();
            $existingToken = $pdo->prepare('SELECT id FROM nexa_mailbox_oauth_token WHERE email_account_id = ? LIMIT 1');
            $existingToken->execute([$emailAccount->getId()]);
            $existingTokenId = $existingToken->fetchColumn();

            if ($existingTokenId) {
                $pdo->prepare(
                    'UPDATE nexa_mailbox_oauth_token SET access_token_encrypted = ?, refresh_token_encrypted = ?, expires_at = ?, scopes = ? WHERE id = ?'
                )->execute([
                    $attempt['access_token_encrypted'], $attempt['refresh_token_encrypted'],
                    $attempt['token_expires_at'], $attempt['scopes'], $existingTokenId,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO nexa_mailbox_oauth_token ' .
                    '(id, tenant_id, service_id, email_account_id, provider, email_address, access_token_encrypted, refresh_token_encrypted, expires_at, scopes) ' .
                    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $this->uuid(), $tenantId, self::CRM_SERVICE_ID, $emailAccount->getId(), $provider,
                    $attempt['email_address'], $attempt['access_token_encrypted'], $attempt['refresh_token_encrypted'],
                    $attempt['token_expires_at'], $attempt['scopes'],
                ]);
            }

            return ['emailAccountId' => $emailAccount->getId(), 'emailAddress' => $attempt['email_address']];
        });
    }

    /** @return array{accessToken:string,expiresAt:DateTimeImmutable} */
    public function refreshAccessToken(string $provider, string $refreshToken): array
    {
        $curl = curl_init($this->tokenEndpoint($provider));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId($provider),
                'client_secret' => $this->clientSecret($provider),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $data = is_string($response) ? json_decode($response, true) : null;

        if ($error !== '' || $status !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            throw new RuntimeException(ucfirst($provider) . ' token refresh failed: ' . ($error ?: 'provider rejected the request'));
        }

        return [
            'accessToken' => $data['access_token'],
            'expiresAt' => (new DateTimeImmutable())->modify('+' . ((int) ($data['expires_in'] ?? 3600)) . ' seconds'),
        ];
    }

    public function cipher(): ProviderSecretCipher
    {
        return $this->cipher;
    }

    /**
     * Shared by OAuthSmtpHandler and OAuthImapHandler - looks up the
     * connected mailbox's token, refreshing it a little ahead of actual
     * expiry so neither a send nor a fetch ever races a token going stale
     * mid-request.
     *
     * @return array{accessToken:string,emailAddress:string,provider:string}
     */
    public function getValidAccessToken(string $emailAccountId): array
    {
        $pdo = $this->entityManager->getPDO();
        $statement = $pdo->prepare('SELECT * FROM nexa_mailbox_oauth_token WHERE email_account_id = ? LIMIT 1');
        $statement->execute([$emailAccountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('This mailbox is no longer connected. Please reconnect it.');
        }

        $accessToken = $this->cipher->decrypt($row['access_token_encrypted']);
        $expiresAt = new DateTimeImmutable($row['expires_at']);

        if ($expiresAt <= (new DateTimeImmutable())->modify('+2 minutes')) {
            $refreshToken = $this->cipher->decrypt($row['refresh_token_encrypted']);
            $refreshed = $this->refreshAccessToken((string) $row['provider'], $refreshToken);
            $accessToken = $refreshed['accessToken'];

            $pdo->prepare('UPDATE nexa_mailbox_oauth_token SET access_token_encrypted = ?, expires_at = ? WHERE id = ?')
                ->execute([$this->cipher->encrypt($accessToken), $refreshed['expiresAt']->format('Y-m-d H:i:s.u'), $row['id']]);
        }

        return [
            'accessToken' => $accessToken,
            'emailAddress' => (string) $row['email_address'],
            'provider' => (string) $row['provider'],
        ];
    }

    private function lockPendingAttempt(PDO $pdo, string $provider, string $state): ?string
    {
        $statement = $pdo->prepare(
            'SELECT id, expires_at FROM nexa_mailbox_oauth_attempt WHERE provider = ? AND state_hash = ? AND consumed_at IS NULL LIMIT 1'
        );
        $statement->execute([$provider, hash('sha256', $state)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row || new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
            return null;
        }

        return (string) $row['id'];
    }

    private function failAttempt(PDO $pdo, string $attemptId, string $reason): void
    {
        $pdo->prepare('UPDATE nexa_mailbox_oauth_attempt SET status = \'failed\', failure_reason = ? WHERE id = ?')
            ->execute([$reason, $attemptId]);
    }

    /** @return array{accessToken:string,refreshToken:string,expiresAt:DateTimeImmutable,idToken:?string} */
    private function exchangeCode(string $provider, string $code): array
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
                'redirect_uri' => $this->redirectUri($provider),
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $data = is_string($response) ? json_decode($response, true) : null;

        if ($error !== '' || $status !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            throw new RuntimeException(ucfirst($provider) . ' token exchange failed: ' . ($error ?: 'provider rejected the request'));
        }
        // A refresh token is essential (send access must outlive the ~1hr
        // access token) but providers only guarantee returning one on the
        // FIRST consent (see the `prompt=consent` forcing above for Google);
        // a user who somehow reaches this without one must reconnect.
        if (!is_string($data['refresh_token'] ?? null)) {
            throw new RuntimeException(ucfirst($provider) . ' did not grant offline access. Please reconnect and approve continued access.');
        }

        return [
            'accessToken' => $data['access_token'],
            'refreshToken' => $data['refresh_token'],
            'expiresAt' => (new DateTimeImmutable())->modify('+' . ((int) ($data['expires_in'] ?? 3600)) . ' seconds'),
            'idToken' => is_string($data['id_token'] ?? null) ? $data['id_token'] : null,
            // The scope ACTUALLY granted, per the provider's own response -
            // never assume the request and the grant match. This is what
            // exposed the real bug: the code previously stored what was
            // *requested* (this.scope()) instead of what was *granted*,
            // which masked whether Google was actually including
            // https://mail.google.com/ in the token or silently dropping it.
            'grantedScope' => is_string($data['scope'] ?? null) ? $data['scope'] : '',
        ];
    }

    /**
     * Reads the email claim straight off the id_token JWT without a
     * cryptographic signature check. Unlike Tools/Auth/SocialAuthService
     * (which verifies signatures because a browser-supplied token there
     * directly grants a login session), this id_token only ever arrives via
     * OUR OWN server-to-server exchangeCode() call over TLS to the real
     * provider token endpoint - there is no untrusted channel for it to have
     * been forged on, so re-verifying its signature is redundant here.
     */
    private function resolveEmailAddress(string $provider, array $token): string
    {
        $idToken = $token['idToken'];
        if ($idToken === null) {
            throw new RuntimeException(ucfirst($provider) . ' did not return an identity token.');
        }

        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new RuntimeException('Malformed identity token.');
        }

        $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);
        $email = is_array($payload) ? ($payload['email'] ?? null) : null;

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(ucfirst($provider) . ' did not return a usable email address.');
        }

        return strtolower(trim($email));
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, ['google', 'microsoft'], true)) {
            throw new RuntimeException('Unsupported mail provider.');
        }
    }

    // Discovered the hard way: gmail.send is a Gmail REST API scope only -
    // it does NOT work for raw SMTP XOAUTH2 (Google's SMTP server rejects it
    // with a 334 challenge asking for scope "https://mail.google.com/").
    // That broader scope is what Google's classic IMAP/SMTP XOAUTH2 actually
    // requires - there is no narrower "send only" scope for SMTP transport,
    // only for the REST API, which this architecture deliberately doesn't use
    // (see the class docblock's architecture decision).
    // Google's mail.google.com scope already covers IMAP (it's the
    // full-mailbox scope, not a send-only one) - no separate IMAP scope to
    // request. Microsoft's send-only SMTP.Send scope does NOT cover IMAP,
    // so IMAP.AccessAsUser.All is requested alongside it; a mailbox
    // connected before this scope was added will only get useImap=true
    // once the user reconnects (see grantIncludesImapScope()).
    private function scope(string $provider): string
    {
        return $provider === 'google'
            ? 'openid email https://mail.google.com/'
            : 'openid email offline_access https://outlook.office.com/SMTP.Send https://outlook.office.com/IMAP.AccessAsUser.All';
    }

    // Checked against the provider's OWN response (see exchangeCode()'s
    // grantedScope), never assumed from what was requested - this is what a
    // real bug turned out to hinge on (see MailOAuthService's usage in
    // handleCallback()). The actual send permission is the load-bearing one
    // to check per provider; openid/email are secondary.
    private function grantIncludesRequiredScope(string $provider, string $grantedScope): bool
    {
        $granted = explode(' ', trim($grantedScope));
        $required = $provider === 'google' ? 'https://mail.google.com/' : 'https://outlook.office.com/SMTP.Send';

        return in_array($required, $granted, true);
    }

    // Fetching is an enhancement on top of sending, not a reason to block
    // the connection - if IMAP access wasn't granted (e.g. an org's consent
    // policy declines it, or a not-yet-reconnected Microsoft mailbox from
    // before this scope existed), useImap is simply left off rather than
    // failing the whole connect.
    private function grantIncludesImapScope(string $provider, string $grantedScope): bool
    {
        $granted = explode(' ', trim($grantedScope));
        $required = $provider === 'google' ? 'https://mail.google.com/' : 'https://outlook.office.com/IMAP.AccessAsUser.All';

        return in_array($required, $granted, true);
    }

    // Personal Microsoft accounts (outlook.com/hotmail.com/live.com/msn.com)
    // and business Microsoft 365/Exchange Online mailboxes are DIFFERENT
    // SMTP submission systems, discovered the hard way: a real connect +
    // send against smtp.office365.com failed with "535 5.7.3 Authentication
    // unsuccessful" for a genuine, validly-issued token because that host is
    // Exchange Online-only. Personal accounts submit via smtp-mail.outlook.com.
    private const PERSONAL_MICROSOFT_DOMAINS = ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'];

    private function smtpHost(string $provider, string $emailAddress = ''): string
    {
        if ($provider === 'google') {
            return 'smtp.gmail.com';
        }

        $domain = strtolower((string) strrchr($emailAddress, '@'));
        $domain = $domain !== '' ? substr($domain, 1) : '';

        return in_array($domain, self::PERSONAL_MICROSOFT_DOMAINS, true)
            ? 'smtp-mail.outlook.com'
            : 'smtp.office365.com';
    }

    private function imapHost(string $provider, string $emailAddress = ''): string
    {
        if ($provider === 'google') {
            return 'imap.gmail.com';
        }

        $domain = strtolower((string) strrchr($emailAddress, '@'));
        $domain = $domain !== '' ? substr($domain, 1) : '';

        return in_array($domain, self::PERSONAL_MICROSOFT_DOMAINS, true)
            ? 'imap-mail.outlook.com'
            : 'outlook.office365.com';
    }

    // Deliberately always "common", NOT Tools/Auth's tenant-restricted
    // NEXA_AUTH_MICROSOFT_TENANT_ID. That restriction is correct for
    // *logging into the CRM* (only our own org's accounts should do that),
    // but wrong here: connecting a mailbox is about the USER's own Microsoft
    // identity, whatever it is. Discovered the hard way - forcing a personal
    // outlook.com account through our business tenant signs them in as a
    // guest, which issues a token with the right scope but no real mailbox
    // behind it, so SMTP correctly rejects it with 535 even though nothing
    // about the request itself is wrong.
    private function authorizationEndpoint(string $provider): string
    {
        return $provider === 'microsoft'
            ? 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'
            : 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    private function tokenEndpoint(string $provider): string
    {
        return $provider === 'microsoft'
            ? 'https://login.microsoftonline.com/common/oauth2/v2.0/token'
            : 'https://oauth2.googleapis.com/token';
    }

    // Google, like Microsoft, can fall back to the existing LOGIN app's
    // client id/secret (Google allows one client to hold multiple,
    // separately-consented scope sets - a dedicated nexaMailGoogle* client
    // is still preferred for a cleaner consent screen, but isn't required).
    // Whichever client is used, it must additionally have gmail.send added
    // to its consent screen and this mail callback URL added as an extra
    // authorized redirect URI - the login app's existing scope/redirect
    // alone are not enough.
    private function clientId(string $provider): string
    {
        $key = 'NEXA_MAIL_' . strtoupper($provider) . '_CLIENT_ID';
        $loginKey = 'NEXA_AUTH_' . strtoupper($provider) . '_CLIENT_ID';
        return trim((string) (getenv($key) ?: getenv($loginKey) ?: ($provider === 'google'
            ? ($this->config->get('nexaMailGoogleClientId', '') ?: $this->config->get('oidcClientId', ''))
            : $this->config->get('nexaMicrosoftClientId', ''))));
    }

    private function clientSecret(string $provider): string
    {
        $key = 'NEXA_MAIL_' . strtoupper($provider) . '_CLIENT_SECRET';
        $loginKey = 'NEXA_AUTH_' . strtoupper($provider) . '_CLIENT_SECRET';
        return trim((string) (getenv($key) ?: getenv($loginKey) ?: ($provider === 'google'
            ? ($this->config->get('nexaMailGoogleClientSecret', '') ?: $this->config->get('oidcClientSecret', ''))
            : $this->config->get('nexaMicrosoftClientSecret', ''))));
    }

    private function redirectUri(string $provider): string
    {
        $key = 'NEXA_MAIL_' . strtoupper($provider) . '_REDIRECT_URI';
        $configured = trim((string) (getenv($key) ?: $this->config->get(
            $provider === 'google' ? 'nexaMailGoogleRedirectUri' : 'nexaMailMicrosoftRedirectUri',
            ''
        )));
        return $configured !== ''
            ? $configured
            : rtrim((string) $this->config->get('siteUrl'), '/') . '/api/v1/Nexa/mail/oauth/' . $provider . '/callback';
    }

    private function randomUrlToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
