<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/Auth/AuthProviderRegistry.php';

use Espo\Custom\Tools\Auth\AuthProviderRegistry;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
};

$assert(AuthProviderRegistry::normalize([]) === [], 'Providers must be hidden by default.');
$assert(AuthProviderRegistry::normalize(['google' => false]) === [], 'Disabled providers must be hidden.');

$providers = AuthProviderRegistry::normalize([
    'google' => true,
    'unknown' => true,
]);
$assert(count($providers) === 1, 'Only allow-listed enabled providers may be published.');
$assert($providers[0]['key'] === 'google', 'Google provider metadata is missing.');
$assert(
    $providers[0]['startUrl'] === 'api/v1/Nexa/auth/provider/google/start',
    'Provider entry point must remain behind the M04 contract.'
);

$signupSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/Signup/SignupService.php'
);
$assert(str_contains($signupSource, 'random_int(0, 99999999)'), 'Verification must use an eight-digit random code.');
$assert(str_contains($signupSource, "INTERVAL 15 MINUTE"), 'Resent codes must expire after 15 minutes.');
$assert(
    !str_contains($signupSource, "result['verificationCode']") &&
    !str_contains($signupSource, 'canExposeLocalVerification'),
    'Verification codes must only be delivered through email and never returned by the signup API.'
);
$authConfigSource = file_get_contents(
    dirname(__DIR__, 2) . '/scripts/dev/configure-auth-experience.php'
);
$assert(
    str_contains($authConfigSource, 'InjectableFactory::class'),
    'Native auth configuration must create ConfigWriter through Espo injectable services.'
);
$loginAdapterSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/client/custom/login-patch.js'
);
$loginTemplateSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/client/custom/res/templates/login-modern.tpl'
);
$assert(
    str_contains($loginAdapterSource, 'showForgotPassword: true'),
    'Tenant-aware password recovery must remain reachable when legacy SMTP UI flags are absent.'
);
$assert(
    str_contains($loginTemplateSource, 'Good to see you again') &&
    str_contains($loginTemplateSource, 'modern-login-proof') &&
    str_contains($loginTemplateSource, 'type="button" class="modern-login-forgot"') &&
    substr_count($loginTemplateSource, 'data-action="nexaHome"') === 2 &&
    str_contains($loginAdapterSource, 'location.assign(applicationBaseUrl.href)'),
    'The primary sign-in view must render the distinct Nexa authentication experience.'
);
$assert(
    !str_contains($loginTemplateSource, 'recovery-username') &&
    str_contains($loginTemplateSource, 'name="email" type="email"'),
    'Password recovery must request only the globally reserved email address.'
);
$resetTemplateSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/client/custom/res/templates/password-reset.tpl'
);
$assert(
    str_contains($loginAdapterSource, 'showRecoveryMessage') &&
    str_contains($loginAdapterSource, 'setTimeout(dismissRecoveryMessage') &&
    str_contains($loginAdapterSource, 'isError ? 10000 : 7000') &&
    !str_contains($resetTemplateSource, 'generatePassword') &&
    !str_contains($resetTemplateSource, 'passwordPreview'),
    'Recovery notices must auto-dismiss and the reset form must not expose generator tools.'
);
$landingSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/public/landing/script.js'
);
$landingTemplateSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/public/landing/index.html'
);
$assert(
    !str_contains($landingSource, 'result.verificationCode') &&
    !str_contains($landingTemplateSource, 'data-local-code'),
    'The verification screen must never render a local verification code.'
);
$assert(
    str_contains($landingSource, "localStorage.setItem(") &&
    str_contains($landingSource, "localStorage.removeItem('espo-user-anotherUser')") &&
    str_contains($landingSource, "'espo-user-auth'") &&
    str_contains($landingSource, "location.replace(applicationUrl('?login=1'))") &&
    !str_contains($landingSource, 'location.assign(result.loginUrl)'),
    'Successful verification must retain its loading state and hand off directly to the authenticated application.'
);
$loginCssSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/client/custom/css/modern-login.css'
);
$landingCssSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/public/landing/styles.css'
);
$assert(
    str_contains($loginAdapterSource, "applicationUrl('client/custom/img/google-g.svg')") &&
    str_contains($landingSource, "applicationUrl('client/custom/img/google-g.svg')") &&
    str_contains($loginCssSource, '.modern-social-button--google') &&
    str_contains($landingCssSource, '.social-auth-button--google'),
    'Google sign in and signup must share recognizable provider branding.'
);
$assert(
    str_contains($loginAdapterSource, "classList.add('is-error')") &&
    str_contains($loginCssSource, '.modern-recovery-message.is-error') &&
    str_contains($landingSource, "message.classList.add('is-error')") &&
    str_contains($landingCssSource, '.state-note.is-error'),
    'Authentication failures must use the danger feedback treatment.'
);
$assert(
    str_contains($authConfigSource, "'applicationName'") &&
    str_contains($authConfigSource, "'CRM_NAME'"),
    'Existing installations must receive product branding from the shared environment contract.'
);
$assert(
    str_contains($signupSource, 'strtolower($email)') && str_contains($signupSource, '$code'),
    'Code digests must be bound to email.'
);

$recoverySource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/Auth/RecoveryService.php'
);
$assert(str_contains($recoverySource, 'If the email matches an account'), 'Recovery response must be neutral.');
$assert(str_contains($recoverySource, 'count($rows) !== 1'), 'Recovery must require one unambiguous tenant identity.');
$assert(
    str_contains($recoverySource, 'resolveIdentity(string $email)') &&
    !str_contains($recoverySource, ':username'),
    'Recovery must resolve the tenant from the globally reserved email without requesting a username.'
);

$recoveryEmailBody = file_get_contents(
    dirname(__DIR__, 2) .
    '/espocrm/custom/Espo/Custom/Resources/templates/passwordChangeLink/en_US/User/body.tpl'
);
$recoveryEmailSubject = file_get_contents(
    dirname(__DIR__, 2) .
    '/espocrm/custom/Espo/Custom/Resources/templates/passwordChangeLink/en_US/User/subject.tpl'
);
$coreRecoverySource = file_get_contents(
    dirname(__DIR__, 2) .
    '/espocrm/application/Espo/Tools/UserSecurity/Password/RecoveryService.php'
);
$assert(
    str_contains($recoveryEmailSubject, 'Reset your Nexa CRM password') &&
    str_contains($recoveryEmailBody, '<table role="presentation"') &&
    str_contains($recoveryEmailBody, 'href="{{link}}"') &&
    str_contains($recoveryEmailBody, '{{expiresIn}}') &&
    str_contains($recoveryEmailBody, 'If you did not request a password reset'),
    'Password recovery email must retain the branded, responsive and security-aware layout.'
);
$assert(
    str_contains($coreRecoverySource, 'setIsHtml(true)') &&
    str_contains($coreRecoverySource, 'expiresIn'),
    'Password recovery must render branded HTML with the configured expiry.'
);
$assert(
    str_contains($coreRecoverySource, 'RESEND_COOLDOWN_SECONDS = 60') &&
    str_contains($coreRecoverySource, 'replaceExistingRequest($existingRequest, $request)') &&
    strpos($coreRecoverySource, '$this->send(') <
        strpos($coreRecoverySource, '$this->replaceExistingRequest(') &&
    str_contains($authConfigSource, 'PASSWORD_RECOVERY_RESEND_COOLDOWN_SECONDS') &&
    !str_contains($coreRecoverySource, 'Denied for $userId, already sent.'),
    'Recovery resend must enforce a configurable cooldown and rotate only after mail is accepted.'
);

$tenantResolverSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/application/Espo/Core/Tenant/TenantResolver.php'
);
$assert(
    str_contains($tenantResolverSource, 'resolvePasswordChangeRequest'),
    'Shared-domain password reset must resolve tenant from its opaque request ID.'
);
$passwordResetActionSource = file_get_contents(
    dirname(__DIR__, 2) .
    '/espocrm/application/Espo/Tools/UserSecurity/Api/PostChangePasswordByRequest.php'
);
$assert(
    str_contains($passwordResetActionSource, 'resolvePasswordChangeRequest($requestId)') &&
    str_contains($passwordResetActionSource, 'tenantContextStore->runWith(') &&
    strpos($passwordResetActionSource, 'resolvePasswordChangeRequest($requestId)') <
        strpos($passwordResetActionSource, 'changePasswordByRecovery($requestId, $password)'),
    'Password reset submission must restore tenant context before changing the password.'
);

$userFinderSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/application/Espo/Core/Authentication/Helper/UserFinder.php'
);
$assert(
    str_contains($tenantResolverSource, 'entity_email_address') &&
    str_contains($tenantResolverSource, 'ea.lower = :email') &&
    str_contains($userFinderSource, 'FILTER_VALIDATE_EMAIL') &&
    str_contains($userFinderSource, '[\'emailAddress\' => strtolower($username)]'),
    'Login identity must resolve the same tenant user by unique email or username.'
);
$providerRegistrySource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/Auth/AuthProviderRegistry.php'
);
$microsoftValidatorSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/Auth/MicrosoftIdTokenValidator.php'
);
$assert(
    !str_contains($providerRegistrySource, '$configured[\'microsoft\'] = false') &&
    str_contains($providerRegistrySource, "'_CLIENT_SECRET'") &&
    str_contains($providerRegistrySource, "preg_replace('~/callback/?$~', '/start'") &&
    str_contains($microsoftValidatorSource, "get('tid')") &&
    str_contains($microsoftValidatorSource, 'getAud()') &&
    str_contains($microsoftValidatorSource, 'nonceHash') &&
    str_contains($microsoftValidatorSource, 'new Rsa(\'RS256\', $keys)'),
    'Microsoft provider must require credentials and validate tenant, audience, nonce and signature.'
);
$assert(
    str_contains($authConfigSource, 'AUTH_SESSION_IDLE_MINUTES') &&
    str_contains($authConfigSource, "'authTokenMaxIdleTime'") &&
    str_contains(
        file_get_contents(
            dirname(__DIR__, 2) . '/espocrm/application/Espo/Core/Authentication/Authentication.php'
        ),
        'isAuthTokenIdleExpired'
    ),
    'Interactive sessions must enforce the configured idle timeout during authentication requests.'
);
$mainTemplateSource = file_get_contents(dirname(__DIR__, 2) . '/espocrm/html/main.html');
$assert(
    !str_contains($mainTemplateSource, 'Opening your workspace') &&
    !str_contains($mainTemplateSource, 'class="nexa-app-loading"'),
    'Normal application refreshes must not render the blocking workspace loader.'
);
$socialSource = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/custom/Espo/Custom/Tools/Auth/SocialAuthService.php'
);
$socialMigration = file_get_contents(
    dirname(__DIR__, 2) . '/database/shared/migrations/0006_social_identity.sql'
);
$progressiveMigration = file_get_contents(
    dirname(__DIR__, 2) . '/database/shared/migrations/0007_progressive_signup.sql'
);
$landingMarkup = file_get_contents(
    dirname(__DIR__, 2) . '/espocrm/public/landing/index.html'
);
$assert(
    str_contains($socialSource, 'hash_equals($attempt[\'nonce_hash\']') &&
    str_contains($socialSource, 'validateSignature') &&
    str_contains($socialSource, "email_verified') !== true") &&
    str_contains($socialSource, 'Nexa social authentication failed for provider'),
    'Google sign in must validate nonce, signature and verified email.'
);
$assert(
    str_contains($socialMigration, 'UNIQUE KEY uq_nexa_external_provider_subject') &&
    str_contains($socialMigration, 'consumed_at'),
    'Social identity schema must prevent duplicate subjects and OAuth replay.'
);

$assert(
    str_contains($socialSource, 'private SignupService $signupService') &&
    str_contains($socialSource, 'beginSocial(') &&
    !str_contains($socialSource, 'private function signup(') &&
    str_contains($socialSource, '#nexa-onboarding='),
    'New social identities must resume onboarding instead of provisioning in the OAuth callback.'
);
$assert(
    str_contains($socialSource, "failureUrl('social_auth_failed', \$intent, \$plan)") &&
    str_contains($socialSource, "if (\$intent === 'signup')") &&
    str_contains($landingSource, "params.get('socialError')"),
    'Failed social signup must return to onboarding with a visible error instead of opening login.'
);$assert(
    str_contains($loginAdapterSource, "const socialHash = location.hash.startsWith('#nexa-social=')") &&
    str_contains($loginAdapterSource, 'showLoginUrl(socialHash)') &&
    str_contains($loginAdapterSource, 'const socialPayload = socialHash'),
    'Social login must preserve its fragment handoff until the browser establishes the session.'
);
$assert(
    str_contains($progressiveMigration, 'CREATE TABLE IF NOT EXISTS nexa_signup_attempt') &&
    str_contains($progressiveMigration, 'public_token_hash') &&
    str_contains($signupSource, "!== 'ready'") &&
    str_contains($signupSource, 'email_verified_at'),
    'Progressive signup must persist opaque attempts and provision only a verified ready identity.'
);
$assert(
    str_contains($landingMarkup, 'data-signup-method') &&
    str_contains($landingMarkup, 'data-email-start') &&
    str_contains($landingMarkup, 'data-name-fields') &&
    str_contains($landingMarkup, 'data-email-fields') &&
    str_contains($landingSource, "api('/complete'") &&
    str_contains($landingSource, "api('/verify'"),
    'Landing signup must expose required identity details and separate password and verification states.'
);

fwrite(STDOUT, 'Authentication experience contract suite passed.' . PHP_EOL);
