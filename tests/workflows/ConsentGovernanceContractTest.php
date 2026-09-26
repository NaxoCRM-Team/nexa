<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$leadCapture = $read('espocrm/application/Espo/Resources/metadata/entityDefs/LeadCapture.json');
$targetList = $read('espocrm/application/Espo/Modules/Crm/Resources/metadata/entityDefs/TargetList.json');
$document = $read('espocrm/application/Espo/Modules/Crm/Resources/metadata/entityDefs/Document.json');
$contact = $read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json');
$migration = $read('database/shared/migrations/0043_add_consent_governance.sql');
$auditMigration = $read('database/shared/migrations/0044_govern_consent_audit_history.sql');
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = $read('espocrm/custom/Espo/Custom/Resources/routes.json');
$service = $read('espocrm/custom/Espo/Custom/Tools/Consent/ConsentService.php');
$registry = $read('espocrm/client/custom/src/product-surface-registry.js');
$workspace = $read('espocrm/client/custom/src/views/consent/workspace.js');

foreach (['fieldList', 'duplicateCheck', 'optInConfirmation', 'formCaptcha', 'formFrameAncestors', 'targetList'] as $marker) {
    $assert(str_contains($leadCapture, '"' . $marker . '"'), "Native Lead Capture capability {$marker} must be retained.");
}
foreach (['contacts', 'leads', 'accounts', 'optedOut'] as $marker) {
    $assert(str_contains($targetList, '"' . $marker . '"'), "Native Target List capability {$marker} must be retained.");
}
foreach (['file', 'folder'] as $marker) {
    $assert(str_contains($document, '"' . $marker . '"'), "Native Document capability {$marker} must be retained.");
}
foreach (['marketingStatus', 'legalBasis', 'doNotContact', 'doNotContactChannels'] as $marker) {
    $assert(str_contains($contact, '"' . $marker . '"'), "Contact consent summary field {$marker} is missing.");
}

foreach (['nexa_consent_purpose', 'nexa_consent_event', 'nexa_consent_state'] as $table) {
    $assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS `{$table}`"), "Consent migration is missing {$table}.");
    $entry = array_values(array_filter($manifest['tables'] ?? [], static fn (array $item): bool => ($item['name'] ?? null) === $table));
    $assert(count($entry) === 1, "Ownership manifest is missing {$table}.");
    $assert(($entry[0]['classification'] ?? null) === 'serviceOwned', "{$table} must be service owned.");
    $assert(($entry[0]['serviceScope'] ?? null) === 'required', "{$table} must require service scope.");
}
foreach (['tenant_id', 'service_id', 'policy_version', 'evidence_note', 'privacy_notice_url', 'source_event_id'] as $marker) {
    $assert(str_contains($migration, "`{$marker}`"), "Consent evidence schema is missing {$marker}.");
}
foreach (['/Nexa/consent/workspace', '/Nexa/consent/purposes', '/Nexa/consent/decisions', '/Nexa/consent/contact/:id'] as $route) {
    $assert(str_contains($routes, $route), "Consent API route {$route} is missing.");
}
$assert(str_contains($routes, '/Nexa/consent/decisions/:id/void'), 'Governed consent soft-void route is missing.');
foreach (['supersedes_event_id', 'superseded_by_event_id', 'voided_at', 'voided_by_id', 'void_reason'] as $marker) {
    $assert(str_contains($auditMigration, "`{$marker}`"), "Consent audit governance is missing {$marker}.");
}
$routeDefinitions = json_decode($routes, true, 512, JSON_THROW_ON_ERROR);
$consentActions = array_filter($routeDefinitions, static fn (array $route): bool => str_starts_with((string) ($route['actionClassName'] ?? ''), 'Espo\\Custom\\Tools\\Consent\\Api\\'));
$assert(count($consentActions) >= 4, 'Consent routes must use authenticated API actions.');
foreach (['tenant_id=? AND service_id=?', 'ContactLifecycleService', 'setCommunicationPreference', 'marketing_communications', 'sales_outreach', 'customer_service'] as $marker) {
    $assert(str_contains($service, $marker), "Consent service is missing {$marker}.");
}
$assert(str_contains($registry, "['nexa-consent-privacy', 'Consent & Privacy', '#NexaConsent', 'fas fa-user-shield']"), 'Consent & Privacy must be an active tenant administration destination.');
$assert(str_contains($workspace, "Espo.Ajax.getRequest('Contact'"), 'Consent workspace must use live native Contact search.');
$assert(str_contains($workspace, 'profileImageId') && str_contains($workspace, 'Nexa/contact-profile-image/'), 'Consent Contact search must reuse tenant-scoped profile images.');
$assert(str_contains($workspace, 'this.getDateTime().toDisplay(value)') && !str_contains($workspace, 'toDisplayDateTime'), 'Consent history must use the supported EspoCRM date formatter.');
$assert(str_contains($workspace, 'data-history-search') && str_contains($workspace, 'correct-decision') && str_contains($workspace, 'void-decision'), 'Consent history must provide live search and governed row actions.');
$assert(str_contains($workspace, 'validateDecisionForm') && str_contains($workspace, 'data-field-error'), 'Consent forms must provide field-level required validation.');
$assert(str_contains($workspace, 'Nexa/consent/decisions'), 'Consent workspace must record evidence-backed decisions through the consent API.');
$assert(!preg_match('/isolation-(alpha|beta)|30000000-0000-4000-8000-00000000000[12]/', $service), 'Consent service must not hard-code demo tenants.');

echo "Consent governance contracts passed.\n";
