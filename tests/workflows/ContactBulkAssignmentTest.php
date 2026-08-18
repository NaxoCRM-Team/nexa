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
$modal = $read('espocrm/client/custom/src/views/contact/modals/bulk-assign.js');
$template = $read('espocrm/client/custom/res/templates/contact/modals/bulk-assign.tpl');
$translations = $read('espocrm/custom/Espo/Custom/Resources/i18n/en_US/Contact.json');

foreach (['/Nexa/contact/assignees', '/Nexa/contact/assign'] as $path) {
    if (!array_filter($routes, static fn (array $route): bool =>
        ($route['route'] ?? '') === $path && empty($route['noAuth']))) {
        throw new RuntimeException("Authenticated Contact assignment route {$path} is missing.");
    }
}

$mustContain("this.removeMassAction('massUpdate')", $recordList, 'Contact lists must replace general Mass Update.');
$mustContain("name: 'assign'", $recordList, 'Contact lists must register the focused Assign action.');
$mustContain("Nexa/contact/assign", $recordList, 'Assignment must use the protected Contact endpoint.');
$mustContain('Select up to 500 individual contacts', $recordList, 'Unbounded all-result assignment must be rejected.');
$mustContain('Search tenant users', $template, 'The owner picker must expose a clear search control.');
$mustContain("search?.addEventListener('input'", $modal, 'Owner search must update live as the user types.');
$mustContain("Nexa/contact/assignees", $modal, 'The modal must load tenant-scoped assignees.');
$mustContain("'No owner'", $modal, 'The assignment workflow must support clearing ownership.');
$mustContain("User::ATTRIBUTE_IS_ACTIVE => true", $service, 'Only active users may be offered for assignment.');
$mustContain('User::TYPE_REGULAR, User::TYPE_ADMIN', $service, 'Portal, API and system identities must be excluded.');
$mustContain("check(\$contact, Table::ACTION_EDIT)", $service, 'Every selected Contact must pass record edit ACL.');
$mustContain("\$service->update(\$id, \$data, UpdateParams::create())", $service, 'Assignment must use the record service and its hooks.');
$mustContain('"assign": "Assign"', $translations, 'The Contact mass action must use the Assign label.');

foreach (['tenant-a', 'tenant-b', 'isolation-alpha'] as $literal) {
    if (str_contains($service . $recordList . $modal, $literal)) {
        throw new RuntimeException("Contact assignment must not hardcode tenant {$literal}.");
    }
}

echo "Contact bulk assignment contracts passed.\n";
