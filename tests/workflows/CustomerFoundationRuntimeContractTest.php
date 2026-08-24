<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($content === false) {
        throw new RuntimeException("Unable to read {$relative}.");
    }
    return $content;
};
$mustContain = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException($message);
    }
};

$hook = $read('espocrm/custom/Espo/Custom/Hooks/Common/CustomerFoundation.php');
$recorder = $read('espocrm/custom/Espo/Custom/Tools/Customer/CustomerFoundationRecorder.php');
$query = $read('espocrm/custom/Espo/Custom/Tools/Customer/CustomerFoundationQueryService.php');
$api = $read('espocrm/custom/Espo/Custom/Tools/Customer/Api/GetFoundation.php');
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);

$mustContain('implements AfterSave, AfterRemove, AfterRelate, AfterUnrelate', $hook, 'The customer foundation must cover native saves, removals and links.');
$mustContain('private CustomerFoundationRecorder $recorder', $hook, 'The common hook must delegate to one coordination service.');
$mustContain('tenantContextStore->current()', $recorder, 'Every projection must derive ownership from trusted TenantContext.');
$mustContain('nexa_lifecycle_assignment', $recorder, 'Lifecycle assignments must be maintained centrally.');
$mustContain('nexa_lifecycle_transition', $recorder, 'Lifecycle changes must retain transition history.');
$mustContain('nexa_relationship_edge', $recorder, 'Native associations must project relationship history.');
$mustContain('nexa_identity_link', $recorder, 'Contact identities must project to the identity map.');
$mustContain('nexa_timeline_event', $recorder, 'Existing activities must project to the unified customer timeline.');
$mustContain('nexa_audit_event', $recorder, 'Customer mutations must be audited.');
$mustContain('nexa_outbox_event', $recorder, 'Customer mutations must emit durable automation events.');
$mustContain("private const ACTIVITY_TYPES = ['Note', 'Task', 'Meeting', 'Call', 'Email', 'Document']", $recorder, 'Existing CRM activity entities must remain the automation sources.');
$mustContain('acl->checkEntityRead($entity)', $query, 'Foundation reads must prove native record permission before querying projections.');
$mustContain('tenantContextStore->require()', $query, 'Foundation reads must require trusted tenant and service context.');
$mustContain('acl->checkEntityRead($related)', $query, 'Relationship results must filter inaccessible related records.');
$mustContain("setHeader('Cache-Control', 'private, no-store')", $api, 'Customer snapshots must not enter shared browser caches.');

$route = array_values(array_filter($routes, static fn (array $item): bool =>
    ($item['route'] ?? '') === '/Nexa/customer/:entityType/:id/foundation' &&
    ($item['method'] ?? '') === 'get'
));
if (count($route) !== 1 || !empty($route[0]['noAuth'])) {
    throw new RuntimeException('The customer foundation snapshot must have one authenticated GET route.');
}

foreach (['tenant-a', 'tenant-b', 'isolation-alpha', 'isolation-beta'] as $literal) {
    if (str_contains($hook . $recorder . $query . $api, $literal)) {
        throw new RuntimeException("Customer coordination must not hardcode {$literal}.");
    }
}

echo "Customer foundation runtime contracts passed.\n";
