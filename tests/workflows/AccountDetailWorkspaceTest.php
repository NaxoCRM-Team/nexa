<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if ($content === false) throw new RuntimeException("Unable to read {$relative}.");
    return $content;
};
$mustContain = static function (string $needle, string $content, string $message): void {
    if (!str_contains($content, $needle)) throw new RuntimeException($message);
};

$clientDefs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Account.json'), true, flags: JSON_THROW_ON_ERROR);
$view = $read('espocrm/client/custom/src/views/account/record/detail-workspace.js');
$styles = $read('espocrm/client/custom/css/crm-workflows.css');

if (($clientDefs['recordViews']['detail'] ?? '') !== 'custom:views/account/record/detail-workspace') {
    throw new RuntimeException('Account detail must use the company workspace record view.');
}

$mustContain("['views/record/detail']", $view, 'The company workspace must extend the native Account record view.');
$mustContain('super.afterRender()', $view, 'Native Account rendering must complete before the workspace is composed.');
$mustContain('await this.model.fetch()', $view, 'The company summary must refresh before displaying saved values.');
$mustContain('data-nexa-company-actions', $view, 'Native Account edit, save and action controls must be preserved.');
$mustContain("querySelectorAll(':scope > .record-buttons, :scope > .edit-buttons')", $view, 'View and edit controls must move together into the company toolbar.');
$mustContain('data-nexa-company-edit-fields', $view, 'Native account properties must remain available during edit mode.');
$mustContain("engagementTab('activity', 'Activity', true)", $view, 'Activity must be the first active company workspace tab.');
if (str_contains($view, 'data-nexa-company-tab="overview"')) {
    throw new RuntimeException('Account detail must not repeat company properties in an Overview tab.');
}
$mustContain('data-nexa-company-field="annualRevenue"', $view, 'Company profile must display annual revenue.');
$mustContain('data-nexa-company-field="emailAddress"', $view, 'Company profile must display company email.');
$mustContain('data-nexa-company-field="numberOfEmployees"', $view, 'Company profile must display employee count.');
$mustContain('data-nexa-company-field="parentAccount"', $view, 'Company profile must display its parent company.');
$mustContain('data-nexa-company-field="teams"', $view, 'Company profile must display its owning teams.');
$mustContain('data-nexa-company-field="tags"', $view, 'Company profile must display Account tags.');
$mustContain('renderCustomProperties(shell)', $view, 'Readable custom Account fields must have a metadata-driven display surface.');
$mustContain("checkField('Account', name, 'read')", $view, 'Custom Account properties must honor field-level read permission.');
$mustContain('data-nexa-company-field="leadScore"', $view, 'Company profile must display lead score.');
$mustContain('data-nexa-company-field="industry"', $view, 'Company profile must display industry.');
$mustContain('data-nexa-company-field="type"', $view, 'Company profile must display account type.');
if (str_contains($view, 'data-nexa-company-native-activity')) {
    throw new RuntimeException('Native Account side panels must not duplicate the aggregated Activity timeline.');
}
$mustContain('data-nexa-company-field="modifiedAt"', $view, 'Company profile must display the last modified date.');
$mustContain('modifiedAuditValue()', $view, 'Company profile must combine the modified timestamp and modifying user.');
$mustContain("this.model.get('modifiedByName')", $view, 'Company profile must identify the last modifying user.');
$mustContain("this.model.get('modifiedById')", $view, 'The last modifying user must link to their user record when available.');
$mustContain('formatDateTime', $view, 'The modified audit value must include date and time.');
$mustContain('data-nexa-company-relationship-summary', $view, 'Company hierarchy and relationships need a dedicated summary.');
$mustContain("{key: 'subsidiaries'", $view, 'Company hierarchy must expose subsidiaries.');
$mustContain("{key: 'documents'", $view, 'Company relationships must expose documents.');
$mustContain('data-nexa-company-contact-cards', $view, 'Associated Contacts must render as a dedicated card rail.');
$mustContain('renderContactRail(shell)', $view, 'The Contact card rail must use the Account-scoped contact response.');
$mustContain("select: 'id,name,title,emailAddress,phoneNumber", $view, 'Contact cards must include title, email and phone data.');
if (str_contains($view, 'data-nexa-company-contact-search') || str_contains($view, 'data-nexa-company-contact-sort')) {
    throw new RuntimeException('The compact Account contact rail must not display search or sort controls.');
}
$mustContain('Native Account relationship panels stay inside the concealed grid.', $view, 'Duplicated native Account relationship panels must remain concealed.');
$mustContain('data-relationship-add', $view, 'Visible relationship cards must expose an add action where supported.');
$mustContain('data-relationship-menu-toggle', $view, 'Visible relationship cards must expose a compact actions menu.');
$mustContain("maxSize: 6, orderBy: 'createdAt', order: 'desc'", $view, 'Relationship cards must load the latest six accessible records.');
$mustContain('data-relationship-toggle', $view, 'Relationship cards must provide expand and collapse controls.');
$mustContain('definition.total > definition.list.length', $view, 'Relationship cards must show View all only when more records exist.');
$mustContain("contacts: `#Contact/account", $view, 'The Contacts metric must open the Account-filtered Contact list.');
$mustContain("Espo.Ajax.getRequest(`Account/\${id}/\${key}`", $view, 'Company metrics must use ACL-scoped relationship endpoints.');
$mustContain('role="tablist"', $view, 'Company workspace navigation must expose accessible tab semantics.');
$mustContain("engagementTab('notes', 'Notes')", $view, 'Account workspace must expose aggregated Notes.');
$mustContain("engagementTab('tasks', 'Tasks')", $view, 'Account workspace must expose aggregated Tasks.');
$mustContain("engagementTab('meetings', 'Meetings')", $view, 'Account workspace must expose aggregated Meetings.');
$mustContain("engagementTab('calls', 'Calls')", $view, 'Account workspace must expose aggregated Calls.');
$mustContain("engagementTab('emails', 'Email')", $view, 'Account workspace must expose aggregated Email.');
if (str_contains($view, 'data-nexa-company-tab-count')) {
    throw new RuntimeException('Account engagement tabs must not display total-count badges.');
}
$mustContain("this.loadRelatedContacts('contactsPrimary')", $view, 'The Contact rail must include Contacts using the primary Account relationship.');
$mustContain("this.loadRelatedContacts('contacts')", $view, 'The Contact rail must include Contacts using the many-to-many Account relationship.');
$mustContain('offset < total', $view, 'Account engagement must paginate through every accessible Contact.');
$mustContain('Nexa/account/${encodeURIComponent(this.model.id)}/timeline', $view, 'Company engagement must load through the protected Account timeline API.');
$mustContain('data-nexa-engagement-more', $view, 'Company engagement must support server-side pagination.');
$mustContain('data-nexa-engagement-search', $view, 'Every engagement tab must provide live search.');
$mustContain('data-nexa-engagement-filter-toggle', $view, 'Every engagement tab must expose filters.');
$mustContain('data-nexa-engagement-period', $view, 'Account engagement must support date-period filtering.');
$mustContain('data-nexa-engagement-owner', $view, 'Account engagement must support owner filtering.');
$mustContain('engagementMatchesPeriod', $view, 'Account engagement date filters must evaluate record timestamps.');
$mustContain('companyFactEditConfig', $view, 'Editable company facts must use field-aware inline editors.');
$mustContain("this.model.save(this.readCompanyFactEditor", $view, 'Inline company edits must persist through the scoped Account model.');
$mustContain("checkField('Account', name, 'edit')", $view, 'Inline Account editors must honor field-level edit permission.');
$mustContain("config.type === 'currency' ? 'number'", $view, 'Annual revenue must use the inline currency editor.');
$mustContain('[`${field}Currency`]: currency', $view, 'Currency edits must persist a valid currency code with the amount.');
$mustContain("event.key === 'Enter'", $view, 'Inline company edits must save with Enter.');
$mustContain("event.key === 'Escape'", $view, 'Inline company edits must cancel with Escape.');
$mustContain("editor.addEventListener('focusout'", $view, 'Inline company edits must save when focus leaves the cell.');
if (str_contains($view, 'nexa-company-fact-editor-controls')) {
    throw new RuntimeException('Company facts must not display Save and Cancel buttons inside the inline editor.');
}
$mustContain('assignedUserName || record.createdByName', $view, 'Engagement rows must identify their responsible user.');
$mustContain('isNoteComment(record)', $view, 'Note comments must not be counted as independent company notes.');
$mustContain('isLoggedInteraction(record)', $view, 'Logged channel interactions must stay in Activity instead of the Notes tab.');
$mustContain('data-nexa-company-engagement-toggle', $view, 'Account engagement records must expand and collapse.');
$mustContain('data-nexa-company-engagement-actions-toggle', $view, 'Expanded Account engagement records must expose an Actions menu.');
$mustContain('data-nexa-company-engagement-pin', $view, 'Account engagement actions must support permission-checked pinning.');
$mustContain('data-nexa-company-engagement-delete', $view, 'Account engagement actions must support permission-checked deletion.');
$mustContain("this.getAcl().checkModel(model, 'delete')", $view, 'Account engagement deletion must use record-level permissions.');
$mustContain('Espo.Ajax.deleteRequest(`${record._entityType}/', $view, 'Account engagement deletion must use the scoped entity API.');
$mustContain("document.body.classList.remove('nexa-account-detail-page')", $view, 'Account page state must be cleaned up on navigation.');

foreach (['.nexa-company-grid', 'grid-template-columns:', '.nexa-company-metrics', '.nexa-company-native-actions', '.nexa-company-edit-host', ':has(.edit-buttons:not(.hidden))', '.nexa-company-engagement-record', '.nexa-company-tab-count', '.nexa-company-audit', '.nexa-company-contact-card', '.nexa-company-relationship-card', '.nexa-company-hidden-association-section', '@media (max-width: 760px)'] as $contract) {
    $mustContain($contract, $styles, "Account responsive workspace styling is missing {$contract}.");
}
$mustContain('body.nexa-account-detail-page {', $styles, 'Desktop Account detail must lock document scrolling.');
$mustContain('grid-template-rows: 72px minmax(0, 1fr)', $styles, 'Account toolbar and content grid must fit the application viewport.');
$mustContain('max-height: 100%', $styles, 'The nested Account grid must not grow beyond its viewport row.');
$mustContain('overflow-x: hidden', $styles, 'The Account grid must keep the third column scrollbar inside the visible edge.');
$mustContain('overscroll-behavior-y: contain', $styles, 'Each Account column must contain its own scrolling.');
$mustContain('overflow-y: scroll', $styles, 'Each Account column must expose a persistent vertical scrollbar.');
$mustContain('.nexa-company-main::-webkit-scrollbar-thumb', $styles, 'Account column scrollbars must remain visible in Chromium browsers.');
$mustContain('::-webkit-scrollbar-button:single-button:vertical:decrement', $styles, 'Account column scrollbars must expose a visible up control.');
$mustContain('::-webkit-scrollbar-button:single-button:vertical:increment', $styles, 'Account column scrollbars must expose a visible down control.');
$mustContain('data-nexa-company-scrollbar', $view, 'The right Account column must install an explicit visible scrollbar.');
$mustContain('setPointerCapture', $view, 'The right Account scrollbar thumb must support mouse dragging.');
$mustContain('.nexa-company-scrollbar-track', $styles, 'The right Account column must expose a persistent scrollbar track.');

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($view, $literal)) throw new RuntimeException("Account workspace must not hardcode {$literal}.");
}

echo "Account company workspace contracts passed.\n";
