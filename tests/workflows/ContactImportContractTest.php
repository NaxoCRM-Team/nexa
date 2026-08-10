<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/espocrm/custom/Espo/Custom/Tools/ContactImport/ContactImportService.php';
$routesPath = $root . '/espocrm/custom/Espo/Custom/Resources/routes.json';
$clientDefsPath = $root . '/espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/Contact.json';
$templatePath = $root . '/espocrm/client/custom/res/templates/contact/import.tpl';
$viewPath = $root . '/espocrm/client/custom/src/views/contact/import.js';

foreach ([$servicePath, $routesPath, $clientDefsPath, $templatePath, $viewPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException("Missing Contact import contract file: {$path}");
    }
}

$service = file_get_contents($servicePath);
$routes = json_decode((string) file_get_contents($routesPath), true, 512, JSON_THROW_ON_ERROR);
$clientDefs = json_decode((string) file_get_contents($clientDefsPath), true, 512, JSON_THROW_ON_ERROR);
$template = file_get_contents($templatePath);
$view = file_get_contents($viewPath);

$expectedHeader = "'first_name', 'last_name', 'email', 'phone', 'account_name'";

if (!str_contains((string) $service, $expectedHeader)) {
    throw new RuntimeException('The approved simple Contact CSV header is not present.');
}

foreach (['MAX_FILE_BYTES = 65 * 1024 * 1024', 'MAX_ROW_LIMIT = 100000', 'withHeaderRow(true)'] as $contract) {
    if (!str_contains((string) $service, $contract)) {
        throw new RuntimeException("Missing Contact import service contract: {$contract}");
    }
}

$routeMap = [];
foreach ($routes as $route) {
    $routeMap[$route['route'] . ':' . $route['method']] = true;
}

foreach ([
    '/Nexa/contact-import/template:get',
    '/Nexa/contact-import/preview:post',
    '/Nexa/contact-import/preview-page:post',
    '/Nexa/contact-import/confirm:post',
] as $route) {
    if (!isset($routeMap[$route])) {
        throw new RuntimeException("Missing Contact import route: {$route}");
    }
}

if (($clientDefs['controller'] ?? null) !== 'custom:controllers/contact') {
    throw new RuntimeException('Contact does not use the Nexa controller.');
}

foreach (['Download template', 'Maximum rows for this import', 'Validate and preview', 'Import contacts', 'Back to Contacts', 'Previous', 'Next'] as $label) {
    if (!str_contains((string) $template, $label)) {
        throw new RuntimeException("Missing accessible Contact import control: {$label}");
    }
}

foreach (['contentType: \'text/csv; charset=UTF-8\'', "timeout: 300000", 'renderAccountMatch', 'loadPreviewPage'] as $behavior) {
    if (!str_contains((string) $view, $behavior)) {
        throw new RuntimeException("Missing Contact import client behavior: {$behavior}");
    }
}

echo "Contact import contract tests passed.\n";
