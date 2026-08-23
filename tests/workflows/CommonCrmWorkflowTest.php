<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($content === false) throw new RuntimeException("Unable to read {$relative}.");
    return $content;
};
$mustContain = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message);
};

$workflow = $read('espocrm/client/custom/crm-workflows.js');
$workspaceCss = $read('espocrm/client/custom/css/tenant-workspace.css');
$workspace = $read('espocrm/client/custom/tenant-workspace.js');
$appClientDefs = $read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/App.json');
$footerTemplate = $read('espocrm/client/res/templates/site/footer.tpl');
$compiledTemplates = $read('espocrm/client/lib/templates.tpl');
$mainHtml = $read('espocrm/html/main.html');
$processor = $read('espocrm/application/Espo/Core/Tenant/TenantQueryProcessor.php');
$resolver = $read('espocrm/application/Espo/Core/Tenant/TenantResolver.php');
$acl = $read('espocrm/application/Espo/Core/AclManager.php');
$order = $read('espocrm/application/Espo/Core/Select/Order/Applier.php');
$export = $read('espocrm/application/Espo/Tools/Export/Export.php');

foreach (['views/list', 'views/detail', 'views/edit', 'views/record/list', 'views/record/detail', 'views/record/edit'] as $view) {
    $mustContain("'{$view}'", $workflow, "Shared workflow enhancer must cover {$view}.");
}
$mustContain('processSelect(', $processor, 'CRM reads must be centrally tenant scoped.');
$mustContain('processInsert(', $processor, 'CRM creates must receive tenant and service ownership.');
$mustContain('processWrite(Update|Delete', $processor, 'CRM updates and deletes must share tenant-safe write scoping.');
$mustContain('query instanceof Update => $this->processWrite', $processor, 'CRM updates must be tenant and service scoped.');
$mustContain('query instanceof Delete => $this->processWrite', $processor, 'CRM deletes must be tenant and service scoped.');
$mustContain("'serviceId' => \$context->serviceId", $processor, 'CRM ownership must include the resolved service.');
$mustContain('ts.status = :tenantServiceStatus', $resolver, 'Tenant resolution must require an active tenant service.');
$mustContain('public function checkField(', $acl, 'Field-level access must remain centrally enforced.');
$mustContain('checkField($this->user, $this->entityType, $orderBy)', $order, 'Sorting must reject forbidden fields.');
$mustContain('checkField($params->getEntityType(), $item)', $export, 'Export must remove forbidden fields.');
$mustContain('aria-required', $workflow, 'Required form fields must expose accessible state.');
$mustContain('response?.status === 409', $workflow, 'Edit conflicts must expose a distinct recovery state.');
$mustContain('body.has-navbar.nexa-side-navigation > #content.container', $workspaceCss, 'Authenticated workspace pages must own a responsive full-width container rule.');
if (!preg_match('/body\.has-navbar\.nexa-side-navigation\s*>\s*#content\.container\s*\{[^}]*max-width:\s*none;/s', $workspaceCss)) {
    throw new RuntimeException('Authenticated workspace pages must not inherit the fixed desktop container cap.');
}
$mustContain('body.nexa-side-navigation > footer', $workspaceCss, 'The authenticated body footer must be removed from the workspace canvas.');
$mustContain('body.has-navbar:not(.nexa-shell-ready) #navbar', $workspaceCss, 'The native authenticated shell must remain hidden until Nexa navigation is ready.');
$mustContain("define('client/custom/tenant-workspace'", $workspace, 'Tenant navigation must be a deterministic application navbar module.');
$mustContain('document.body.classList.add(\'nexa-shell-ready\')', $workspace, 'The Nexa navbar must explicitly release the authenticated shell guard.');
$mustContain('"navbarView": "client/custom/tenant-workspace"', $appClientDefs, 'The application must register the Nexa navbar before authenticated rendering.');
if (str_contains($workspace, 'MutationObserver')) {
    throw new RuntimeException('The eagerly loaded navbar module must not observe document-wide mutations before authentication renders.');
}
$mustContain('.nexa-sidebar-footer', $workspaceCss, 'The authenticated copyright must be positioned in the side navigation.');
$mustContain('new Date().getFullYear()', $workspace, 'The workspace footer year must come from the browser clock.');
$mustContain('ensureSidebarFooter(navigation)', $workspace, 'Navbar rendering must install the sidebar copyright.');
$mustContain("mark.textContent = '\\u00A9'", $workspace, 'Minimized navigation must retain a compact copyright mark.');
$mustContain('`© Nexa CRM ${year}`', $workspace, 'SPA footer rerenders must restore Nexa branding and current-year order.');
foreach ([$footerTemplate, $compiledTemplates, $mainHtml] as $footerSource) {
    $mustContain('data-nexa-current-year', $footerSource, 'Every authenticated footer runtime source must expose the dynamic-year target.');
    $mustContain('Nexa CRM', $footerSource, 'Every authenticated footer runtime source must display Nexa branding.');
    if (str_contains($footerSource, 'Powered by EspoCRM')) {
        throw new RuntimeException('The application footer must not display Powered by EspoCRM.');
    }
}
$aboutTemplate = $read('espocrm/client/res/templates/about.tpl');
$mustContain('EspoCRM open-source software', $aboutTemplate, 'The About surface must retain the required EspoCRM legal notice.');

foreach (['tenant-a', 'tenant-b', 'demo-admin'] as $literal) {
    if (str_contains($workflow, $literal)) throw new RuntimeException("Workflow code must not hardcode {$literal}.");
}

echo "Common CRM workflow and security contracts passed.\n";
