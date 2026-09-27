<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$application = file_get_contents($root . '/espocrm/application/Espo/Core/Application.php');
$middleware = file_get_contents(
    $root . '/espocrm/application/Espo/Core/Tenant/Api/TenantContextMiddleware.php'
);

if (!is_string($application) || !is_string($middleware)) {
    throw new RuntimeException('Unable to read tenant bootstrap sources.');
}

foreach ([
    'resolveLoginRequestTenant' => 'outer login-identity resolution',
    'isSharedLoginApiRequest' => 'exact failed-login API handling',
    "'/api/v1/App/user'" => 'exact native login endpoint',
    'isPublicPlatformApiRequest' => 'exact public API policy',
    "'/api/v1/Nexa/signup'" => 'signup allow-list',
    "'/api/v1/I18n'" => 'public login translations',
    "'/api/v1/Settings'" => 'public login settings',
    "str_ends_with(rtrim(\$requestPath, '/'), '/login')" => 'portable shared-login shell',
] as $marker => $requirement) {
    if (!str_contains($application, $marker)) {
        throw new RuntimeException("Application is missing {$requirement}.");
    }
}

foreach ([
    'extractLoginIdentifier',
    'resolveLoginIdentifier',
    'isPublicPlatformRoute',
    "return \$this->errorResponse(403, 'Tenant unavailable')",
] as $marker) {
    if (!str_contains($middleware, $marker)) {
        throw new RuntimeException("Tenant middleware is missing {$marker}.");
    }
}

echo "Shared login bootstrap tests passed.\n";
