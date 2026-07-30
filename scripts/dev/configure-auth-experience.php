<?php

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\ConfigWriter;

$root = dirname(__DIR__, 2);
$environmentPath = $root . DIRECTORY_SEPARATOR . '.env';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $value = substr($argument, strlen('--env='));
        $isAbsolute = str_starts_with($value, DIRECTORY_SEPARATOR) ||
            (strlen($value) > 2 && ctype_alpha($value[0]) && $value[1] === ':');
        $environmentPath = $isAbsolute ? $value : $root . DIRECTORY_SEPARATOR . $value;
    }
}

if (!is_file($environmentPath)) {
    fwrite(STDERR, 'Environment file not found: ' . $environmentPath . PHP_EOL);
    exit(1);
}

$environment = [];
foreach (file($environmentPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $environment[trim($key)] = trim($value);
}

chdir($root . DIRECTORY_SEPARATOR . 'espocrm');
require_once 'bootstrap.php';

$enabled = static fn (string $key): bool =>
    filter_var($environment[$key] ?? false, FILTER_VALIDATE_BOOL) === true;

$recoveryCooldown = filter_var(
    $environment['PASSWORD_RECOVERY_RESEND_COOLDOWN_SECONDS'] ?? '60',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 3600]]
);
if ($recoveryCooldown === false) {
    fwrite(STDERR, 'PASSWORD_RECOVERY_RESEND_COOLDOWN_SECONDS must be from 0 to 3600.' . PHP_EOL);
    exit(1);
}

$application = new Application();
$factory = $application->getContainer()->getByClass(InjectableFactory::class);
$writer = $factory->create(ConfigWriter::class);
$writer->setMultiple([
    // Apply product branding to existing installations as well as clean setups.
    'applicationName' => ($environment['CRM_NAME'] ?? '') ?: 'Nexa CRM',
    'passwordRecoveryNoExposure' => true,
    'passwordRecoveryResendCooldown' => $recoveryCooldown,
    'nexaPublicAuthProviders' => [
        'google' => $enabled('NEXA_AUTH_GOOGLE_ENABLED'),
        'microsoft' => $enabled('NEXA_AUTH_MICROSOFT_ENABLED'),
    ],
    // Google is the first M04 provider implementation. Espo's audited OIDC
    // verifier consumes these settings for signature, audience and expiry checks.
    'oidcClientId' => $environment['NEXA_AUTH_GOOGLE_CLIENT_ID'] ?? '',
    'oidcClientSecret' => $environment['NEXA_AUTH_GOOGLE_CLIENT_SECRET'] ?? '',
    'nexaGoogleRedirectUri' => $environment['NEXA_AUTH_GOOGLE_REDIRECT_URI'] ?? '',
    'nexaMicrosoftClientId' => $environment['NEXA_AUTH_MICROSOFT_CLIENT_ID'] ?? '',
    'nexaMicrosoftClientSecret' => $environment['NEXA_AUTH_MICROSOFT_CLIENT_SECRET'] ?? '',
    'nexaMicrosoftTenantId' => $environment['NEXA_AUTH_MICROSOFT_TENANT_ID'] ?? 'common',
    'nexaMicrosoftRedirectUri' => $environment['NEXA_AUTH_MICROSOFT_REDIRECT_URI'] ?? '',
    // The deployment owns this key. ConfigWriter stores it only in the ignored
    // internal configuration so web requests never need to parse .env.
    'nexaAuthSecretKey' => $environment['NEXA_AUTH_SECRET_KEY'] ?? '',
    'oidcAuthorizationEndpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'oidcTokenEndpoint' => 'https://oauth2.googleapis.com/token',
    'oidcUserInfoEndpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
    'oidcJwksEndpoint' => 'https://www.googleapis.com/oauth2/v3/certs',
    'oidcJwtSignatureAlgorithmList' => ['RS256'],
    'oidcScopes' => ['openid', 'email', 'profile'],
]);
$writer->save();

fwrite(STDOUT, 'Authentication experience configuration applied.' . PHP_EOL);
