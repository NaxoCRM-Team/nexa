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

$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$metadata = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest = json_decode($read('database/shared/table-ownership-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$service = $read('espocrm/custom/Espo/Custom/Tools/Contact/ContactLifecycleService.php');
$migration = $read('database/shared/migrations/0019_add_contact_communication_preferences.sql');
$summaryMigration = $read('database/shared/migrations/0020_add_contact_communication_summary.sql');
$recordList = $read('espocrm/client/custom/src/views/contact/record/list-infinite-v2.js');
$modal = $read('espocrm/client/custom/src/views/contact/modals/communication-preference.js');
$template = $read('espocrm/client/custom/res/templates/contact/modals/communication-preference.tpl');
$nameTemplate = $read('espocrm/client/custom/res/templates/contact/fields/name/list-link-v2.tpl');
$detail = $read('espocrm/client/custom/src/views/contact/record/detail-workspace.js');
$historyApi = $read('espocrm/custom/Espo/Custom/Tools/Contact/Api/GetCommunicationPreferenceHistory.php');

if (!array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/contact/communication-preference' &&
    ($route['method'] ?? '') === 'post' && empty($route['noAuth']))) {
    throw new RuntimeException('The communication-preference endpoint must require authentication.');
}
if (!array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/contact/:id/communication-preferences' &&
    ($route['method'] ?? '') === 'get' && empty($route['noAuth']))) {
    throw new RuntimeException('The communication-preference audit endpoint must require authentication.');
}

foreach (['doNotContact', 'doNotContactChannels', 'doNotContactReason', 'doNotContactNote', 'doNotContactChangedAt', 'doNotContactChangedById'] as $field) {
    if (!isset($metadata['fields'][$field])) throw new RuntimeException("Contact metadata is missing {$field}.");
}

$preferenceTable = null;
foreach ($manifest['tables'] ?? [] as $table) {
    if (($table['name'] ?? '') === 'nexa_communication_preference') $preferenceTable = $table;
}
if (($preferenceTable['classification'] ?? '') !== 'serviceOwned' || ($preferenceTable['serviceScope'] ?? '') !== 'required') {
    throw new RuntimeException('Communication preferences must be tenant-and-service owned.');
}

foreach (['tenant_id', 'service_id', 'contact_id', 'channel', 'status', 'reason', 'changed_by_id'] as $column) {
    $mustContain($column, $migration, "Preference history is missing {$column}.");
}
$mustContain('TenantContextStore', $service, 'Preference writes must use the trusted tenant context.');
$mustContain("check(\$contact, Table::ACTION_EDIT)", $service, 'Every Contact must pass record edit ACL.');
$mustContain("['email', 'phone', 'sms', 'whatsapp', 'postal']", $service, 'All supported channels must be explicit.');
$mustContain('emailAddressIsOptedOut', $service, 'Email restrictions must synchronize the native delivery guard.');
$mustContain('phoneNumberIsOptedOut', $service, 'Phone restrictions must synchronize the native delivery guard.');
$mustContain('INSERT INTO nexa_communication_preference', $service, 'Each change must create compliance history.');
$mustContain('getCommunicationPreferenceHistory', $service, 'Preference history must have a protected tenant-scoped reader.');
$mustContain('p.tenant_id = ? AND p.service_id = ? AND p.contact_id = ?', $service, 'Preference history reads must enforce tenant, service and Contact ownership.');
$mustContain('checkEntityRead($contact)', $service, 'Preference history must enforce Contact read permission.');
$mustContain('changedByName', $service, 'Preference history must resolve the user responsible for each change.');
$mustContain("'createdAt'", $service, 'Preference history must expose its authoritative audit timestamp.');
$mustContain("getRouteParam('id')", $historyApi, 'The history API must read only the Contact selected by the route.');
$mustContain("'Cache-Control', 'private, no-store'", $historyApi, 'Communication audit responses must never use shared caches.');
$mustContain("'type' => 'Post'", $service, 'Preference changes must create native Contact activities.');
$mustContain('Communication restriction removed', $service, 'Restriction removal must also appear in Activities.');
$mustContain('do_not_contact_reason', $summaryMigration, 'The active restriction reason must be available to Contact workspaces.');
$mustContain('do_not_contact_note', $summaryMigration, 'The active internal note must be available to Contact workspaces.');
$mustContain("name: 'setDoNotContact'", $recordList, 'The Contact list must expose Set do not contact.');
$mustContain("name: 'removeDoNotContact'", $recordList, 'The Contact list must expose restriction removal.');
$mustContain('Nexa/contact/communication-preference', $recordList, 'The list must use the protected preference endpoint.');
$mustContain("this.disableButton('update')", $modal, 'The modal action must remain disabled until a reason is selected.');
$mustContain('Select a reason', $template, 'A required reason prompt must be visible.');
$mustContain('nexa-do-not-contact-badge', $nameTemplate, 'Restricted contacts must be identified in list rows.');
$mustContain('doNotContactTitle', $nameTemplate, 'The restriction icon must expose an accessible channel description.');
$mustContain('data-action="removeCommunicationRestriction"', $nameTemplate, 'The restriction icon must open its removal workflow.');
if (str_contains($nameTemplate, 'fa-envelope')) throw new RuntimeException('The list restriction control must use one unambiguous icon.');
$mustContain('this.options.channels', $modal, 'Removal choices must come from active Contact restrictions.');
$mustContain('{{#each channelOptions}}', $template, 'The channel selector must render only channels supplied by the modal.');
$mustContain('array_intersect($current, $channels)', $service, 'Removal must be limited to active restrictions.');
$mustContain('nexa-do-not-contact-badge--profile', $detail, 'Restricted contacts must be identified on their profile.');
$mustContain('<span>Do not contact</span>', $detail, 'The Contact profile badge must pair its icon with a clear label.');
$mustContain('nexa-communication-alert', $detail, 'The Overview must surface the active restriction context.');

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($service . $recordList . $modal, $literal)) {
        throw new RuntimeException("Communication preferences must not hardcode tenant {$literal}.");
    }
}

echo "Contact communication preference contracts passed.\n";
