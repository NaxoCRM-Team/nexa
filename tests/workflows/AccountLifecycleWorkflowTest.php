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
$service = $read('espocrm/custom/Espo/Custom/Tools/Account/AccountLifecycleService.php');
$list = $read('espocrm/client/custom/src/views/account/list-v2.js');
$recordList = $read('espocrm/client/custom/src/views/account/record/list-infinite-v2.js');
$exportModal = $read('espocrm/client/custom/src/views/account/modals/export.js');
$exportTemplate = $read('espocrm/client/custom/res/templates/account/modals/export.tpl');
$exportService = $read('espocrm/custom/Espo/Custom/Tools/ContactExport/ContactExportAuditService.php');
$trash = $read('espocrm/client/custom/src/views/account/trash.js');
$controller = $read('espocrm/client/custom/src/controllers/account.js');
$contactController = $read('espocrm/client/custom/src/controllers/contact.js');
$accountLayout = json_decode($read('espocrm/custom/Espo/Custom/Resources/layouts/Account/list.json'), true, flags: JSON_THROW_ON_ERROR);
$contactCountField = $read('espocrm/client/custom/src/views/account/fields/contact-count-list.js');
$contactCountTemplate = $read('espocrm/client/custom/res/templates/account/fields/contact-count-list.tpl');
$migration = $read('database/shared/migrations/0028_add_account_deletion_audit.sql');
$defs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/Account.json'), true, flags: JSON_THROW_ON_ERROR);
$scopeFilter = $read('espocrm/custom/Espo/Custom/Classes/Select/Account/PrimaryFilters/CreatedByMe.php');
$selectDefs = json_decode($read('espocrm/custom/Espo/Custom/Resources/metadata/selectDefs/Account.json'), true, flags: JSON_THROW_ON_ERROR);

foreach (['/Nexa/account/delete', '/Nexa/account/contact-counts', '/Nexa/account/trash', '/Nexa/account/trash/restore', '/Nexa/account/trash/purge'] as $path) {
    if (!array_filter($routes, static fn (array $route): bool => ($route['route'] ?? '') === $path && empty($route['noAuth']))) {
        throw new RuntimeException("Authenticated Account lifecycle route {$path} is required.");
    }
}

$mustContain("getRDBRepository('Account')->getById(\$id)", $service, 'Account delete must load through the scoped ORM.');
$mustContain("check(\$account, Table::ACTION_DELETE)", $service, 'Account delete must enforce record ownership ACL.');
$mustContain("findLinked(\$id, 'contacts', \$params)", $service, 'Account contact totals must use the ACL-scoped relationship service.');
$mustContain('withMaxSize(1)', $service, 'Account contact totals must fetch only the count and a minimal row set.');
$mustContain("where(['id' => \$id, 'deleted' => true])", $service, 'Account recovery must select only scoped deleted records.');
$mustContain('Only a tenant administrator can manage deleted accounts.', $service, 'Account recovery must be tenant-admin only.');
$mustContain('deleted_at DATETIME', $migration, 'Account deletion audit must retain deletion time.');
$mustContain('deleted_by_id VARCHAR(17)', $migration, 'Account deletion audit must retain the actor.');
if (($defs['fields']['deletedAt']['readOnly'] ?? false) !== true || ($defs['fields']['deletedBy']['readOnly'] ?? false) !== true) {
    throw new RuntimeException('Account deletion audit metadata must be read-only.');
}

$mustContain("importButton.href = '#Account/import'", $list, 'Account list must expose Import beside table controls.');
$mustContain("href = '#Account/trash'", $list, 'Deleted Accounts must open the dedicated recovery workspace.');
$mustContain("Espo.Ajax.postRequest('Nexa/account/delete'", $recordList, 'Account removal must use the protected soft-delete endpoint.');
$mustContain("custom:views/account/modals/delete-confirmation", $recordList, 'Account removal must require typed confirmation.');
$mustContain("custom:views/account/modals/export", $recordList, 'Account exports must use the modern Account export dialog.');
$mustContain("Espo.Ajax.postRequest('Export'", $recordList, 'Account exports must retain the native permission-aware export engine.');
$mustContain("Espo.Ajax.postRequest('Nexa/contact-export/audit'", $recordList, 'Completed Account exports must be recorded in Export Audit.');
$mustContain('Nexa/contact-export/${encodeURIComponent(attachmentId)}/download', $recordList, 'Account exports must use authenticated API downloads.');
if (str_contains($recordList, '?entryPoint=download&id=')) {
    throw new RuntimeException('Account exports must not use the unauthenticated native download entry point.');
}
$mustContain("label: 'Export accounts'", $exportModal, 'The Account export dialog must expose a clear primary command.');
$mustContain('defaultExportName: `Accounts ', $exportModal, 'Account exports must receive a useful dated default name.');
$mustContain('<h4>Export accounts</h4>', $exportTemplate, 'The Account export dialog must identify the exported record type.');
$mustContain("'Selected accounts'", $exportService, 'The export audit boundary must recognize selected Account exports.');
$mustContain("'Filtered accounts'", $exportService, 'The export audit boundary must recognize filtered Account exports.');
$mustContain("? 'Account'", $exportService, 'Account export downloads must re-check Account read access.');
$mustContain("Espo.Ajax.getRequest('Nexa/account/trash'", $trash, 'Account recovery must load the scoped trash collection.');
$mustContain("Espo.Ajax.postRequest('Nexa/account/trash/restore'", $trash, 'Account restore must use the protected endpoint.');
$mustContain("Espo.Ajax.postRequest('Nexa/account/trash/purge'", $trash, 'Account permanent deletion must use the protected endpoint.');
$mustContain("entityType: 'Account'", $controller, 'Account import must preselect the Account entity.');
$mustContain("this.main('views/import/index'", $controller, 'Account import must use the audited import engine.');
$mustContain("collection.url = `Account/\${encodeURIComponent(accountId)}/contacts`", $contactController, 'Account contact navigation must use the scoped relationship endpoint.');
$mustContain("`#Contact/account?\${query.toString()}`", $contactCountField, 'Contact-count badges must open the filtered Contact route.');
$mustContain('View contacts', $contactCountTemplate, 'Contact-count badges must clearly state their action.');
$mustContain('renderAccountScopeTabs()', $list, 'Account list must render My Accounts and All Accounts scopes.');
$mustContain('#Account/list/primaryFilter=createdByMe', $list, 'My Accounts must use the protected server-side primary filter.');
if (($selectDefs['primaryFilterClassNameMap']['createdByMe'] ?? '') !== 'Espo\\Custom\\Classes\\Select\\Account\\PrimaryFilters\\CreatedByMe') {
    throw new RuntimeException('Account createdByMe primary filter metadata is missing.');
}
$mustContain("Cond::column('createdById')", $scopeFilter, 'My Accounts must filter by the authenticated record creator.');
$mustContain('$this->user->getId()', $scopeFilter, 'My Accounts must resolve the current user on the server.');

$layoutNames = array_column($accountLayout, 'name');
foreach (['website', 'numberOfEmployees', 'type'] as $removedColumn) {
    if (in_array($removedColumn, $layoutNames, true)) {
        throw new RuntimeException("Account list must not display {$removedColumn} by default.");
    }
}
if (!in_array('contactCount', $layoutNames, true)) {
    throw new RuntimeException('Account list must display the related Contact total.');
}

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($service . $recordList . $trash, $literal)) {
        throw new RuntimeException("Account lifecycle code must not hardcode {$literal}.");
    }
}

echo "Account import, deletion and recovery contracts passed.\n";
