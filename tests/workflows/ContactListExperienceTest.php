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
$styles = $read('espocrm/client/custom/css/crm-workflows.css');
$layout = json_decode($read('espocrm/custom/Espo/Custom/Resources/layouts/Contact/list.json'), true, flags: JSON_THROW_ON_ERROR);
$location = $read('espocrm/client/custom/src/views/contact/fields/location-list.js');
$locationTemplate = $read('espocrm/client/custom/res/templates/contact/fields/location-list.tpl');
$leadStatus = $read('espocrm/client/custom/src/views/contact/fields/lead-status-list.js');
$leadStatusTemplate = $read('espocrm/client/custom/res/templates/contact/fields/lead-status-list.tpl');
$createdAt = $read('espocrm/client/custom/src/views/contact/fields/created-at-list.js');
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
$mustContain("this.addHandler('input'", $search, 'Contact search must respond to typing.');
$mustContain('this.filterVisibleRows(event.target.value)', $search, 'Contact search must filter loaded rows immediately.');
$mustContain('window.setTimeout(() => this.runLiveSearch(), 320)', $search, 'Contact search must debounce API requests.');
$mustContain('this.updateCollectionSilently()', $search, 'Contact live search must refresh the scoped collection without a blocking loader.');
$mustContain('reset: true', $search, 'Background search must replace stale rows when complete.');
$mustContain('this.collection.where = this.searchManager.getWhere()', $search, 'Background search must use the scoped search manager query.');
$mustContain('this.collection.hasMore()', $infinite, 'Incremental loading must stop at the final scoped page.');
$mustContain('this.showMoreRecords({skipNotify: true}', $infinite, 'Incremental loading must use the native bounded-page loader.');
$mustContain('new MutationObserver(', $infinite, 'Incremental loading must bind after asynchronous list rendering.');
$mustContain('height: clamp(', $styles, 'The Contact table must have a stable scrollable height.');
$mustContain('.nexa-contact-list-page .pagination', $styles, 'Contact pagination controls must be hidden.');

$expectedColumns = ['name', 'emailAddress', 'title', 'account', 'phoneNumber', 'leadStatus', 'address', 'createdAt'];
$actualColumns = array_map(static fn (array $item): string => $item['name'], $layout);
if ($actualColumns !== $expectedColumns) {
    throw new RuntimeException('Contact list columns or their order do not match the approved workspace layout.');
}
if (($layout[2]['label'] ?? '') !== 'Job Title') {
    throw new RuntimeException('Contact title must be presented as Job Title in the list.');
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
$mustContain('font-family: inherit', $styles, 'Contact headers must use consistent typography.');
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
