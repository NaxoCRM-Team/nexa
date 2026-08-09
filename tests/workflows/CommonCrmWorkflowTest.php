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

foreach (['tenant-a', 'tenant-b', 'demo-admin'] as $literal) {
    if (str_contains($workflow, $literal)) throw new RuntimeException("Workflow code must not hardcode {$literal}.");
}

echo "Common CRM workflow and security contracts passed.\n";
