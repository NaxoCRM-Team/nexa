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
$service = $read('espocrm/custom/Espo/Custom/Tools/Contact/ContactLifecycleService.php');
$recordList = $read('espocrm/client/custom/src/views/contact/record/list-infinite-v2.js');
$list = $read('espocrm/client/custom/src/views/contact/list-v2.js');
$deleteModal = $read('espocrm/client/custom/src/views/contact/modals/delete-confirmation.js');
$deleteTemplate = $read('espocrm/client/custom/res/templates/contact/modals/delete-confirmation.tpl');
$trashPage = $read('espocrm/client/custom/src/views/contact/trash.js');
$trashTemplate = $read('espocrm/client/custom/res/templates/contact/trash.tpl');
$purgeModal = $read('espocrm/client/custom/src/views/contact/modals/permanent-delete-confirmation.js');
$cleanup = $read('espocrm/application/Espo/Classes/Jobs/Cleanup.php');
$migration = $read('database/shared/migrations/0016_add_contact_deletion_audit.sql');
$styles = $read('espocrm/client/custom/css/crm-workflows.css');

foreach (['/Nexa/contact/delete', '/Nexa/contact/trash', '/Nexa/contact/trash/restore', '/Nexa/contact/trash/purge'] as $path) {
    if (!array_filter($routes, static fn (array $route): bool =>
        ($route['route'] ?? '') === $path && empty($route['noAuth']))) {
        throw new RuntimeException("Authenticated Contact lifecycle route {$path} is missing.");
    }
}

$mustContain("getRDBRepository('Contact')->getById(\$id)", $service, 'Deletion must load Contacts through the tenant-scoped ORM.');
$mustContain("check(\$contact, Table::ACTION_DELETE)", $service, 'Deletion must enforce record ACL.');
$mustContain('level (own, team or all)', $service, 'Contact deletion must use role-configured ownership levels.');
if (str_contains($service, "get('createdById') !== \$this->user->getId()")) {
    throw new RuntimeException('Contact deletion must not override team or all access with a hardcoded creator check.');
}
$mustContain("\$service->delete(\$id, DeleteParams::create())", $service, 'Deletion must use the native soft-delete service and hooks.');
$mustContain("->withDeleted()", $service, 'Restore must explicitly query recoverable records.');
$mustContain("requireTenantAdmin()", $service, 'Trash and restore must require tenant administration.');
$mustContain("public function purge(array \$ids)", $service, 'Permanent deletion must use the protected Contact lifecycle service.');
$mustContain("where(['id' => \$id, 'deleted' => true])", $service, 'Permanent deletion must verify a tenant-scoped soft-deleted record.');
$mustContain("deleteFromDb(\$entity->getId(), true)", $service, 'Permanent deletion must only remove records already marked deleted.');
$mustContain('purgeRelatedNotes', $service, 'Permanent deletion must clean related CRM notes.');
$mustContain('purgeRelatedAttachments', $service, 'Permanent deletion must clean related attachments.');
$mustContain("set('deletedAt', \$this->now())", $service, 'Deletion must preserve its retention timestamp.');
$mustContain('idx_contact_tenant_deleted_at', $migration, 'Deletion retention lookup must be tenant-indexed.');

$mustContain("count: ids.length", $recordList, 'Delete confirmation must show the selected Contact count.');
$mustContain("Nexa/contact/delete", $recordList, 'Contact deletion must use the protected endpoint.');
$mustContain("Select up to 500 individual contacts", $recordList, 'Unbounded select-all deletion must be prevented.');
$mustContain("Number(input.value) === this.count", $deleteModal, 'The destructive action must require the exact selected count.');
$mustContain('permanently removed after two months', $deleteTemplate, 'The modal must communicate the global retention period.');
$mustContain("cssName = 'nexa-delete-confirmation-modal'", $deleteModal, 'The modal must use a valid single-token DOM identity.');
$mustContain("className = 'dialog nexa-delete-dialog'", $deleteModal, 'The deletion dialog must receive its custom visual class.');
$mustContain('noFullHeight = true', $deleteModal, 'The compact confirmation must not inherit the full-height modal layout.');
$mustContain("this.getUser().isAdmin()", $list, 'The trash control must be tenant-admin-only in the UI.');
$mustContain("href = '#Contact/trash'", $list, 'Restore records must open a dedicated tenant-admin page.');
$mustContain('Restore records', $list, 'The Contact toolbar must use an unambiguous recovery label.');
$mustContain('this.deletedContactTotal', $list, 'Restore records must remain hidden when no deleted contacts exist.');
$mustContain('createButton.after(trashButton)', $list, 'Restore records must be placed beside New Contact.');
$mustContain("Nexa/contact/trash/purge", $trashPage, 'Permanent deletion must use the protected endpoint.');
$mustContain("this.selectedIds.size === 0", $trashPage, 'Permanent deletion must require a selected row.');
$mustContain('Permanently delete', $trashTemplate, 'The recovery page must expose an explicit destructive action.');
$mustContain("this.enableButton('confirm')", $purgeModal, 'Typing the selected count must enable permanent deletion through the modal API.');
$mustContain("this.disableButton('confirm')", $purgeModal, 'An incorrect count must keep permanent deletion disabled.');
$mustContain("Nexa/contact/trash/restore", $trashPage, 'The recovery page must restore through the protected endpoint.');
$mustContain("input [data-name=\"search\"]", $trashPage, 'Deleted-record search must filter live.');
$mustContain("change [data-name=\"dateFrom\"]", $trashPage, 'Recovery must support a deleted-from filter.');
$mustContain("change [data-name=\"dateTo\"]", $trashPage, 'Recovery must support a deleted-to filter.');
$mustContain("change [data-name=\"deletedBy\"]", $trashPage, 'Recovery must filter by the deleting user.');
$mustContain('Deleted by', $trashTemplate, 'The recovery table must show who deleted a record.');
$mustContain('Date deleted', $trashTemplate, 'The recovery table must show the deletion timestamp.');
$mustContain("'deletedById'", $service, 'Trash records must include their deleting user identity.');
$mustContain("'deletedByName'", $service, 'Trash records must include a readable deleting user name.');
$mustContain("private string \$cleanupDeletedRecordsPeriod = '2 months'", $cleanup, 'All deleted records must use the global two-month default.');
if (str_contains($cleanup, 'contactDeletedRecordsPeriod')) {
    throw new RuntimeException('Contact retention must not override the global deleted-record policy.');
}
$mustContain('.nexa-delete-dialog', $styles, 'The centered destructive modal must have dedicated responsive styling.');
$mustContain('.nexa-restore-workspace', $styles, 'The tenant-admin recovery page must have dedicated responsive styling.');
$mustContain('.modal.nexa-delete-dialog.in', $styles, 'The visible deletion overlay must use full-screen flex centering.');
$mustContain('.nexa-delete-dialog > .modal-dialog', $styles, 'Width constraints must apply to the inner dialog, not the overlay.');
$mustContain('justify-content: center;', $styles, 'The deletion dialog must be horizontally centered.');
$mustContain('align-items: center;', $styles, 'The deletion dialog must be vertically centered on desktop.');
$mustContain('.nexa-delete-dialog .modal-footer > .main-btn-group', $styles, 'Deletion actions must use a dedicated horizontal footer group.');
$mustContain('order: 3;', $styles, 'Deletion actions must remain below the modal body.');
$mustContain('gap: 12px;', $styles, 'Deletion actions must have clear separation.');

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($service . $recordList . $trashPage, $literal)) {
        throw new RuntimeException("Contact deletion must not hardcode tenant {$literal}.");
    }
}

echo "Contact deletion and recovery workflow contracts passed.\n";
