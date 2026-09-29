<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$migration = $read('database/shared/migrations/0054_harden_phase4_public_surfaces.sql');
$limiter = $read('espocrm/custom/Espo/Custom/Tools/PublicAccess/PublicRequestLimiter.php');
$cookie = $read('espocrm/custom/Espo/Custom/Tools/Consent/CookieConsentService.php');
$form = $read('espocrm/custom/Espo/Custom/Tools/Form/PublicFormRuntimeService.php');
$landing = $read('espocrm/custom/Espo/Custom/Tools/LandingPage/LandingPageService.php');
$renderer = $read('espocrm/custom/Espo/Custom/Tools/LandingPage/LandingPageRenderer.php');
$click = $read('espocrm/custom/Espo/Custom/EntryPoints/NexaLandingClick.php');
$page = $read('espocrm/custom/Espo/Custom/EntryPoints/NexaLandingPage.php');
$asset = $read('espocrm/custom/Espo/Custom/EntryPoints/NexaLandingAsset.php');

$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `nexa_public_rate_limit`'), 'The public rate-limit ledger is missing.');
$assert(str_contains($limiter, "\$_SERVER['REMOTE_ADDR']") && str_contains($limiter, 'hash_hmac'), 'Public fingerprints must use a protected server-derived address hash.');
foreach (['cookie-config:', 'cookie-receipt:'] as $scope) {
    $assert(str_contains($cookie, $scope), "Cookie public surface {$scope} is not rate limited.");
}
foreach (['form-view:', 'form-submit:'] as $scope) {
    $assert(str_contains($form, $scope), "Form public surface {$scope} is not rate limited.");
}
$assert(str_contains($landing, "'landing-' . \$operation"), 'Landing views, clicks and assets are not protected by the shared limiter.');
$assert(str_contains($renderer, 'signClickDestination') && str_contains($click, 'verifyClickDestination'), 'Landing redirect destinations must be signed and verified.');
$assert(str_contains($click, "!str_starts_with(\$to,'//')"), 'Protocol-relative landing redirects must be rejected.');
$assert(str_contains($page, "frame-ancestors 'self'") && !str_contains($page, 'frame-ancestors *'), 'Landing pages must not allow arbitrary framing.');
foreach ([$page, $asset] as $source) {
    $assert(str_contains($source, 'X-Content-Type-Options'), 'Public landing responses must disable MIME sniffing.');
}

echo "Phase 4 public security contracts passed.\n";
