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

$clientDefs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Contact.json'), true, flags: JSON_THROW_ON_ERROR);
$list = $read('espocrm/client/custom/src/views/contact/list-v2.js');
$search = $read('espocrm/client/custom/src/views/contact/record/search-live-v2.js');
$infinite = $read('espocrm/client/custom/src/views/contact/record/list-infinite-v2.js');
$inlineEditor = $read('espocrm/client/custom/src/table-inline-editor.js');
$accountInline = $read('espocrm/client/custom/src/views/account/record/list-inline.js');
$accountClientDefs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Account.json'), true, flags: JSON_THROW_ON_ERROR);
$titleAction = $read('espocrm/custom/Espo/Custom/Tools/Contact/Api/PostTitle.php');
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$routerPatch = $read('espocrm/client/custom/login-patch.js');
$titleMigration = $read('database/shared/migrations/0015_add_contact_title.sql');
$contactDefs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json'), true, flags: JSON_THROW_ON_ERROR);
$styles = $read('espocrm/client/custom/css/crm-workflows.css');
$layout = json_decode($read('espocrm/custom/Espo/Custom/Resources/layouts/Contact/list.json'), true, flags: JSON_THROW_ON_ERROR);
$location = $read('espocrm/client/custom/src/views/contact/fields/location-list.js');
$locationTemplate = $read('espocrm/client/custom/res/templates/contact/fields/location-list.tpl');
$leadStatus = $read('espocrm/client/custom/src/views/contact/fields/lead-status-list.js');
$leadStatusTemplate = $read('espocrm/client/custom/res/templates/contact/fields/lead-status-list.tpl');
$createdAt = $read('espocrm/client/custom/src/views/contact/fields/created-at-list.js');
$nameView = $read('espocrm/client/custom/src/views/contact/fields/name-v2.js');
$nameListTemplate = $read('espocrm/client/custom/res/templates/contact/fields/name/list-link-v2.tpl');
$addressData = $read('espocrm/custom/Espo/Custom/Classes/AppParams/AddressSubdivisionData.php');

if (($clientDefs['views']['list'] ?? '') !== 'custom:views/contact/list-v2') {
    throw new RuntimeException('Contact must use the live-search list coordinator.');
}
if (($clientDefs['recordViews']['list'] ?? '') !== 'custom:views/contact/record/list-infinite-v2') {
    throw new RuntimeException('Contact must use the incremental record list.');
}

$mustContain("searchView = 'custom:views/contact/record/search-live-v2'", $list, 'Contact list must register live search.');
$mustContain("recordView = 'custom:views/contact/record/list-infinite-v2'", $list, 'Contact list must directly register incremental loading.');
$mustContain('options.pagination = false', $list, 'Contact list must remove page-based navigation.');
$mustContain("this.contactListElement?.classList.remove('nexa-contact-list-page')", $list, 'Contact list scrolling must be released from the exact list node before rendering Contact record pages.');
$mustContain("this.addHandler('input'", $search, 'Contact search must respond to typing.');
$mustContain('this.filterVisibleRows(event.target.value)', $search, 'Contact search must filter loaded rows immediately.');
$mustContain('window.setTimeout(() => this.runLiveSearch(), 320)', $search, 'Contact search must debounce API requests.');
$mustContain('this.updateCollectionSilently()', $search, 'Contact live search must refresh the scoped collection without a blocking loader.');
$mustContain('reset: true', $search, 'Background search must replace stale rows when complete.');
$mustContain('this.collection.where = this.searchManager.getWhere()', $search, 'Background search must use the scoped search manager query.');
$mustContain('this.collection.hasMore()', $infinite, 'Incremental loading must stop at the final scoped page.');
$mustContain('this.showMoreRecords({skipNotify: true}', $infinite, 'Incremental loading must use the native bounded-page loader.');
$mustContain('new MutationObserver(', $infinite, 'Incremental loading must bind after asynchronous list rendering.');
$mustContain("'custom:table-inline-editor'", $infinite, 'Contact must use the shared lightweight table editor.');
$mustContain("emailAddress: {type: 'text'", $infinite, 'Contact email must use a compact text editor.');
$mustContain('Nexa/contact/${encodeURIComponent(model.id)}/title', $infinite, 'Contact Title must use its relationship-aware update action.');
$mustContain("phoneNumber: {type: 'text'", $infinite, 'Contact phone must use a compact text editor.');
$mustContain("leadStatus: {type: 'dropdown'", $infinite, 'Contact Lead Status must use a compact dropdown editor.');
$mustContain("this.view.addHandler('dblclick'", $inlineEditor, 'Editable cells must activate by double-click.');
$mustContain("cell.dataset.field = field", $inlineEditor, 'Editable cells must expose their API field name.');
$mustContain("cell.dataset.type = config.type", $inlineEditor, 'Editable cells must expose their editor type.');
$mustContain("cell.dataset.options", $inlineEditor, 'Dropdown cells must expose their allowed options.');
$mustContain("this.view.getAcl().checkModel(model, 'edit')", $inlineEditor, 'Shared table editing must enforce record edit permission.');
$mustContain("this.view.getAcl().checkField(this.entityType, field, 'edit')", $inlineEditor, 'Shared table editing must enforce field edit permission.');
$mustContain("await model.save({[field]", $inlineEditor, 'Shared table editing must save only the configured field through the authenticated model API.');
$mustContain("{patch: true}", $inlineEditor, 'Shared table editing must use partial record updates.');
$mustContain("editor.addEventListener('blur'", $inlineEditor, 'Shared table editing must save when focus leaves the cell.');
$mustContain("keyEvent.key === 'Escape'", $inlineEditor, 'Shared table editing must support keyboard cancellation.');
$mustContain("cell.innerHTML = this.originalHtml", $inlineEditor, 'Failed updates must restore the original formatted cell.');
$mustContain("'custom:table-inline-editor'", $accountInline, 'Account must use the shared lightweight table editor.');
$mustContain("industry: {type: 'dropdown'", $accountInline, 'Account Industry must support compact dropdown editing.');
$mustContain('if (config.save)', $inlineEditor, 'The shared editor must support secure field-specific persistence adapters.');
$mustContain("settingsContainer.after(importButton)", $list, 'Contact Import must appear beside Columns and Total controls.');
$mustContain("importButton.href = '#Contact/import'", $list, 'Contact Import must open the Nexa Contact import workspace.');
$mustContain("if (href === '#')", $routerPatch, 'The workspace router must handle the Home tab empty fragment.');
$mustContain("activeRouter.navigate('', {trigger: true})", $routerPatch, 'Home must dispatch the authenticated workspace home route.');
$mustContain("getRDBRepository('Contact')->getById(\$id)", $titleAction, 'Title updates must load Contact through the tenant-scoped ORM.');
$mustContain("check(\$contact, Table::ACTION_EDIT)", $titleAction, 'Title updates must enforce record edit access.');
$mustContain("checkField('Contact', 'title', Table::ACTION_EDIT)", $titleAction, 'Title updates must enforce field edit access.');
$mustContain("\$contact->set('title'", $titleAction, 'Title updates must invoke the existing relationship persistence hook.');
$mustContain('ADD COLUMN IF NOT EXISTS title VARCHAR(100)', $titleMigration, 'Contact Title must be available to contacts without Accounts.');
$mustContain('INNER JOIN account_contact', $titleMigration, 'Existing primary relationship titles must be retained during migration.');
if (($contactDefs['fields']['title']['notStorable'] ?? true) !== false ||
    ($contactDefs['fields']['title']['directUpdateDisabled'] ?? true) !== false ||
    !array_key_exists('select', $contactDefs['fields']['title']) ||
    $contactDefs['fields']['title']['select'] !== null) {
    throw new RuntimeException('Contact Title metadata must use the dedicated Contact column.');
}
if (!array_filter($routes, static fn (array $route): bool =>
    ($route['route'] ?? '') === '/Nexa/contact/:id/title' &&
    ($route['method'] ?? '') === 'post' &&
    empty($route['noAuth']))) {
    throw new RuntimeException('The authenticated Contact Title route must be registered.');
}
if (($accountClientDefs['recordViews']['list'] ?? '') !== 'custom:views/account/record/list-inline') {
    throw new RuntimeException('Account must register the shared inline-edit record list.');
}
$mustContain('100dvh - var(--nexa-header-height', $styles, 'The Contact workspace must fit between the application header and footer.');
$mustContain('flex: 1 1 auto;', $styles, 'The Contact table must consume the remaining workspace height.');
$mustContain('overflow: hidden;', $styles, 'The Contact page must keep scrolling inside the record list.');
$mustContain('.nexa-contact-list-page .pagination', $styles, 'Contact pagination controls must be hidden.');
$mustContain('.nexa-inline-cell-editing', $styles, 'Contact inline editing must expose a clear visual state.');
$mustContain('.nexa-cell-editor', $styles, 'The shared editor must use a dedicated cell-sized input style.');
$mustContain('position: absolute;', $styles, 'The inline input must stay inside the existing table cell geometry.');

if (str_contains($infinite, 'inlineEditSave') || str_contains($infinite, '.inlineEdit()')) {
    throw new RuntimeException('Contact table editing must not invoke EspoCRM inline-edit widgets.');
}
if (str_contains($infinite, 'name: {') || str_contains($infinite, 'createdAt: {')) {
    throw new RuntimeException('Contact Name and Create Date must remain read-only in list inline editing.');
}

$expectedColumns = ['name', 'emailAddress', 'title', 'account', 'phoneNumber', 'leadStatus', 'address', 'createdAt'];
$actualColumns = array_map(static fn (array $item): string => $item['name'], $layout);
if ($actualColumns !== $expectedColumns) {
    throw new RuntimeException('Contact list columns or their order do not match the approved workspace layout.');
}
if (($layout[2]['label'] ?? '') !== 'Title') {
    throw new RuntimeException('Contact title must be presented as Title in the list.');
}
if (($layout[5]['view'] ?? '') !== 'custom:views/contact/fields/lead-status-list') {
    throw new RuntimeException('Contact Lead Status must use its semantic badge renderer.');
}
if (($layout[6]['view'] ?? '') !== 'custom:views/contact/fields/location-list') {
    throw new RuntimeException('Contact Location must use its dedicated list renderer.');
}
if (($layout[7]['label'] ?? '') !== 'Create Date' ||
    ($layout[7]['view'] ?? '') !== 'custom:views/contact/fields/created-at-list') {
    throw new RuntimeException('Contact Create Date must use the explicit-year renderer.');
}
$mustContain("this.model.get('addressCity')", $location, 'Location must read the Contact city.');
$mustContain("this.model.get('addressState')", $location, 'Location must read the Contact state.');
$mustContain("this.model.get('addressCountry')", $location, 'Location must read the Contact country.');
$mustContain('client/custom/img/flags/4x3/', $location, 'Location must render flags from portable local assets.');
$mustContain('nexa-contact-location-text', $locationTemplate, 'Location must render city and state in one stable cell.');
$mustContain("\$data['countryCodes']", $addressData, 'Address data must expose ISO codes for Contact flags.');
$mustContain("OpenDeal: 'open-deal'", $leadStatus, 'Open Deal must have a stable badge style.');
$mustContain("Unqualified: 'unqualified'", $leadStatus, 'Unqualified must have a stable badge style.');
$mustContain('nexa-lead-status--{{statusClass}}', $leadStatusTemplate, 'Lead Status must render as a semantic badge.');
$mustContain('.nexa-lead-status--connected', $styles, 'Connected lead badges must have an explicit color.');
$mustContain('td[data-name="emailAddress"]', $styles, 'Contact primary fields must use stable field selectors for emphasis.');
$mustContain('color: #174f46;', $styles, 'Contact primary fields must use the approved dark-green emphasis.');
$mustContain('font-weight: 700;', $styles, 'Contact primary fields must use bold emphasis.');
$mustContain('font-family: inherit', $styles, 'Contact headers must use consistent typography.');
$mustContain("listLinkTemplate = 'custom:contact/fields/name/list-link-v2'", $nameView, 'Contact Name lists must use the avatar renderer.');
$mustContain("Nexa/contact-profile-image/", $nameView, 'Contact list portraits must use the protected tenant-scoped image endpoint.');
$mustContain("URL.revokeObjectURL", $nameView, 'Contact list portrait object URLs must be released.');
$mustContain("this.model.get('firstName') || this.model.get('lastName')", $nameView, 'Contacts without portraits must derive a stable initial.');
$mustContain('nexa-contact-list-avatar', $nameListTemplate, 'Contact list names must render a circular avatar beside the link.');
$mustContain("getSelectAttributeList(callback)", $infinite, 'Contact list queries must explicitly append hidden required fields.');
$mustContain("attributeList.push('profileImageId')", $infinite, 'Every Contact list query must include its hidden portrait identifier.');
$mustContain('.nexa-contact-name-link', $styles, 'Contact name and avatar must use stable inline alignment.');
$mustContain('border-radius: 50%;', $styles, 'Contact list avatars must be circular.');
$mustContain('object-fit: cover;', $styles, 'Contact portraits must crop cleanly inside their circle.');
$mustContain("configuredFormat.includes('Y')", $createdAt, 'Contact Create Date must always include a year.');
$mustContain("label.textContent = 'Total contacts:'", $list, 'Contact totals must have a visible label.');
$mustContain("'<span>Columns</span>'", $list, 'The visible-column selector must have a clear label.');
$mustContain("placeholder', 'Search contacts'", $list, 'Contact search must have a clear visible prompt.');
$flagFiles = glob($root . '/espocrm/client/custom/img/flags/4x3/*.svg') ?: [];
if (count($flagFiles) < 240 || !is_file($root . '/espocrm/client/custom/img/flags/LICENSE.flag-icons')) {
    throw new RuntimeException('The complete licensed local country-flag asset set is required.');
}

require_once $root . '/espocrm/bootstrap.php';
$addressParam = new Espo\Custom\Classes\AppParams\AddressSubdivisionData();
$addressValues = $addressParam->get();
if (($addressValues['countryCodes']['Nigeria'] ?? '') !== 'NG') {
    throw new RuntimeException('Tracked country data must resolve Nigeria to its ISO flag code.');
}

foreach (['tenant-a', 'tenant-b', 'demo-admin'] as $literal) {
    if (str_contains($search . $infinite, $literal)) {
        throw new RuntimeException("Contact list code must not hardcode {$literal}.");
    }
}

echo "Contact live-search and infinite-scroll contracts passed.\n";
