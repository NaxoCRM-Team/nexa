<?php

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$list = $read('espocrm/client/custom/src/views/contact/record/list-infinite-v2.js');
$audit = $read('espocrm/client/custom/src/views/contact/export-audit.js');
$service = $read('espocrm/custom/Espo/Custom/Tools/ContactExport/ContactExportAuditService.php');
$migration = $read('database/shared/migrations/0018_add_contact_export_audit.sql');
$tenantResolver = $read('espocrm/application/Espo/Core/Tenant/TenantResolver.php');
$application = $read('espocrm/application/Espo/Core/Application.php');
$surfaceRegistry = $read('espocrm/client/custom/src/product-surface-registry.js');
$workspaceNavigation = $read('espocrm/client/custom/tenant-workspace.js');
$contactList = $read('espocrm/client/custom/src/views/contact/list-v2.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

foreach ([['post', 'PostAudit'], ['get', 'GetAudit']] as [$method, $class]) {
    $assert((bool) array_filter($routes, static fn (array $route): bool =>
        $route['route'] === '/Nexa/contact-export/audit' &&
        $route['method'] === $method &&
        str_ends_with($route['actionClassName'], $class)
    ), "Missing {$method} Contact export audit route.");
}

$assert((bool) array_filter($routes, static fn (array $route): bool =>
    $route['route'] === '/Nexa/contact-export/:id/download' &&
    $route['method'] === 'get' &&
    str_ends_with($route['actionClassName'], 'GetDownload')
), 'Missing authenticated Contact export download route.');

$assert((bool) array_filter($routes, static fn (array $route): bool =>
    $route['route'] === '/Nexa/contact-export/:id' &&
    $route['method'] === 'delete' &&
    str_ends_with($route['actionClassName'], 'DeleteAudit')
), 'Missing Contact export deletion route.');

$assert(str_contains($list, "Espo.Ajax.postRequest('Export'"), 'Contact export must reuse the native permission-aware exporter.');
$assert(str_contains($list, "Espo.Ajax.postRequest('Nexa/contact-export/audit'"), 'Completed exports must be audited.');
$assert(str_contains($list, "navigate('#Contact/exportAudit'"), 'Completed exports must open Export Audit.');
$assert(str_contains($audit, "Espo.Ajax.getRequest('Nexa/contact-export/audit'"), 'Export Audit must load server-side records.');
$assert(str_contains($service, 'a.tenant_id = :tenantId') && str_contains($service, 'a.service_id = :serviceId'), 'Audit reads must be tenant and service scoped.');
$assert(str_contains($service, "Attachment::ROLE_EXPORT_FILE"), 'Only native export attachments may be audited.');
$assert(str_contains($service, 'fileStorageManager->getContents'), 'Export downloads must read an authorized audit attachment.');
$assert(str_contains($service, 'normalizeExportName'), 'Export names must be validated and normalized on the server.');
$assert(str_contains($service, 'fileStorageManager->unlink'), 'Deleting an export must remove its generated file.');
$assert(str_contains($service, 'removeEntity($attachment)'), 'Deleting an export must remove it from the audit list.');
$assert(str_contains($list, 'exportName'), 'The selected export name must be sent to the audit service.');
$assert(str_contains($audit, 'export-delete-confirmation'), 'Audit deletion must require a confirmation modal.');
$assert(str_contains($list, 'Nexa/contact-export/${encodeURIComponent(attachmentId)}/download'), 'New exports must use the authenticated API download.');
$assert(!str_contains($list, '?entryPoint=download&id='), 'New exports must not navigate through the native entry point.');
$assert(str_contains($audit, 'Nexa/contact-export/${encodeURIComponent(id)}/download'), 'Audit exports must use the authenticated API download.');
$assert(!str_contains($audit, '?entryPoint=download&id='), 'Audit exports must not navigate through the native entry point.');
$assert(str_contains($migration, 'idx_attachment_tenant_export_audit'), 'Export audit metadata needs a scoped lookup index.');
$assert(str_contains($tenantResolver, 'resolveAuthToken'), 'Protected browser downloads must resolve their tenant from the auth-token cookie.');
$assert(str_contains($tenantResolver, "a.token = :token") && str_contains($tenantResolver, 'a.is_active = 1'), 'Cookie tenant discovery must require an active native auth token.');
$assert(str_contains($application, "\$_COOKIE['auth-token']") && str_contains($application, 'resolveAuthToken'), 'Web entry points must use token-based tenant discovery before host fallback.');
$assert(str_contains($surfaceRegistry, "['nexa-import-export', 'Import & Export', '#Contact/exportAudit']"), 'Import & Export must be an active Data & Integrations surface.');
$assert(str_contains($workspaceNavigation, "link ? 'nexa-active-module-link' : 'nexa-planned-module-link'"), 'Delivered submenu surfaces must not be disabled as planned modules.');
$assert(!str_contains($contactList, 'nexa-contact-export-audit-button'), 'The Contact list must not duplicate the Import & Export navigation entry.');

echo "Contact export workflow contracts passed.\n";
