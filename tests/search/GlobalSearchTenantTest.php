<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));

    if ($content === false) {
        throw new RuntimeException("Unable to read {$path}.");
    }

    return $content;
};

$assertContains = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException($message);
    }
};

$service = $read('espocrm/application/Espo/Tools/GlobalSearch/Service.php');
$processor = $read('espocrm/application/Espo/Core/Tenant/TenantQueryProcessor.php');
$client = $read('espocrm/client/custom/src/views/global-search/global-search.js');
$navbar = $read('espocrm/client/custom/tenant-workspace.js');
$metadata = $read('espocrm/custom/Espo/Custom/Resources/metadata/app/clientNavbar.json');

$assertContains('TenantContextStore $tenantContextStore', $service, 'Global search must depend on trusted tenant context.');
$assertContains("serviceEntitlementChecker->isEnabled('crm')", $service, 'Global search must enforce the active CRM service.');
$assertContains('->withStrictAccessControl()', $service, 'Global search must preserve record ACL filtering.');
$assertContains('processSelect(', $processor, 'Tenant processor must scope SELECT queries.');
$assertContains('processUnion(', $processor, 'Tenant processor must scope every global-search UNION child.');
$assertContains('nexaGlobalSearchRecent:${tenant.id}:${userId}', $client, 'Recent searches must be tenant and user scoped.');
$assertContains('checkTabAccess', $read('espocrm/client/lib/original/espo-main.js'), 'Module suggestions must originate from permission-filtered tabs.');
$assertContains('aria-disabled', $navbar, 'Unavailable navigation modules must not be interactive.');
$assertContains('custom:views/global-search/global-search', $metadata, 'Custom global search must be registered in navbar metadata.');

foreach (['tenant-a', 'tenant-b', 'demo-admin'] as $forbiddenLiteral) {
    if (str_contains($service, $forbiddenLiteral) || str_contains($client, $forbiddenLiteral)) {
        throw new RuntimeException("Product search code must not hardcode synthetic tenant value {$forbiddenLiteral}.");
    }
}

echo "Global search tenant, service and permission contracts passed.\n";
