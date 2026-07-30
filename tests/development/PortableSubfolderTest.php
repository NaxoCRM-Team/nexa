<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/espocrm/public/application-path.php';
require_once $root . '/espocrm/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
        exit(1);
    }
};

$assert(
    NexaApplicationPath::fromScriptName('/public/index.php') === '',
    'A virtual-host installation must resolve to the web root.'
);
$assert(
    NexaApplicationPath::fromScriptName('/espocrm_boye/public/index.php') === '/espocrm_boye',
    'A rewritten WampServer subfolder must retain its application mount point.'
);
$assert(
    NexaApplicationPath::fromScriptName('/espocrm_boye/index.php') === '/espocrm_boye',
    'A direct subfolder entry point must retain its application mount point.'
);
$assert(
    NexaApplicationPath::isApplicationRoot('/espocrm_boye/', '/espocrm_boye'),
    'The copied-folder URL must be recognized as the public landing page.'
);
$assert(
    !NexaApplicationPath::isApplicationRoot('/espocrm_boye/client/', '/espocrm_boye'),
    'Nested application routes must not be mistaken for the landing page.'
);
$assert(
    NexaApplicationPath::baseHref('/espocrm_boye') === '/espocrm_boye/',
    'Relative assets and API calls must receive a trailing-slash base URL.'
);
$assert(
    NexaApplicationPath::isRoute('/espocrm_boye/login', '/espocrm_boye', 'login'),
    'Friendly routes must be recognized within a copied application mount point.'
);

$originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['SCRIPT_NAME'] = '/public/api/v1/index.php';
$_SERVER['REQUEST_URI'] = '/espocrm2/espocrm/api/v1/App/user';

$assert(
    \Espo\Core\Utils\Route::detectBasePath() === '/espocrm2/espocrm/api/v1',
    'A WampServer rewrite must preserve the nested API mount point for Slim routing.'
);

$_SERVER['SCRIPT_NAME'] = '/espocrm2/espocrm/api/v1/App/user';
$assert(
    \Espo\Core\Utils\Route::detectBasePath() === '/espocrm2/espocrm/api/v1',
    'An original-route SCRIPT_NAME must not make Slim consume /App/user as its base path.'
);

if ($originalScriptName === null) {
    unset($_SERVER['SCRIPT_NAME']);
} else {
    $_SERVER['SCRIPT_NAME'] = $originalScriptName;
}
if ($originalRequestUri === null) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $originalRequestUri;
}

$publicEntry = file_get_contents($root . '/espocrm/public/index.php');
$apiStarter = file_get_contents($root . '/espocrm/application/Espo/Core/Api/Starter.php');
$landing = file_get_contents($root . '/espocrm/public/landing/index.html');
$landingScript = file_get_contents($root . '/espocrm/public/landing/script.js');
$loginScript = file_get_contents($root . '/espocrm/client/custom/login-patch.js');
$mainHtml = file_get_contents($root . '/espocrm/html/main.html');
$rewriteRules = file_get_contents($root . '/espocrm/.htaccess');
$landingCss = file_get_contents($root . '/espocrm/public/landing/styles.css');

$assert(
    str_contains($landing, '<base href="{{baseHref}}">') &&
    !str_contains($landing, 'href="/?login=') &&
    !str_contains($landing, 'src="/landing/'),
    'Landing navigation and assets must resolve from the injected application base.'
);
$assert(
    str_contains($landingScript, "applicationUrl('api/v1/Nexa/auth/providers')") &&
    str_contains($loginScript, "applicationUrl('api/v1/Nexa/auth/recovery')") &&
    !str_contains($landingScript, "fetch('/api/v1/") &&
    !str_contains($loginScript, "fetch('/api/v1/") &&
    str_contains($loginScript, 'loaderBasePath'),
    'Authentication and signup APIs must not escape to the server root.'
);
$assert(
    str_contains($rewriteRules, 'RewriteRule ^login$ %{ENV:BASE}login/') &&
    str_contains($rewriteRules, 'RewriteRule ^login/$') &&
    !str_contains($landing, 'login=1') &&
    !str_contains($landingCss, "url('/client/") &&
    !str_contains($landingCss, "url('/landing/"),
    'Friendly login navigation and landing assets must remain inside the application mount point.'
);
$assert(
    str_contains($publicEntry, 'setClientBasePath($isFriendlyLoginRequest ? \'../\' : \'\')'),
    'The Espo client loader must step back from /login before resolving application assets.'
);
$assert(
    str_contains($apiStarter, "hash('sha256', \$basePath)") &&
    str_contains($apiStarter, 'setCacheFile($this->routeCacheFile($basePath))'),
    'Slim route caches must be isolated by application mount path.'
);
$assert(
    str_contains($mainHtml, "document.createElement('base')") &&
    str_contains($mainHtml, "window.location.href.split(/[?#]/)[0]") &&
    str_contains($mainHtml, 'showStartupFailure') &&
    str_contains($mainHtml, "window.location.reload()"),
    'Lazy client assets must remain anchored to the original application mount after URL normalization.'
);
$assert(
    str_contains($loginScript, 'App.prototype.initRouter = function') &&
    str_contains($loginScript, 'showApplicationUrl();') &&
    str_contains($loginScript, 'showLoginUrl();'),
    'Authentication must establish the correct pathname before Backbone initializes its router root.'
);

fwrite(STDOUT, '[PASS] Portable root and subfolder paths are supported.' . PHP_EOL);
