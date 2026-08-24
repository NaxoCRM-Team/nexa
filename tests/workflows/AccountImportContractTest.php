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

$service = $read('espocrm/custom/Espo/Custom/Tools/AccountImport/AccountImportService.php');
$routes = json_decode($read('espocrm/custom/Espo/Custom/Resources/routes.json'), true, flags: JSON_THROW_ON_ERROR);
$controller = $read('espocrm/client/custom/src/controllers/account.js');
$view = $read('espocrm/client/custom/src/views/account/import.js');
$template = $read('espocrm/client/custom/res/templates/account/import.tpl');

foreach ([
    "'company_name', 'website', 'phone', 'email', 'industry'",
    'MAX_FILE_BYTES = 65 * 1024 * 1024',
    'MAX_ROW_LIMIT = 100000',
    "SUPPORTED_EXTENSIONS = ['csv', 'xlsx', 'xls']",
    'withHeaderRow(true)',
    'withSkipDuplicateChecking(false)',
    "import('Account', self::ATTRIBUTE_LIST",
    'convertSpreadsheetToCsv',
] as $contract) {
    $mustContain($contract, $service, "Missing Account import service contract: {$contract}");
}

foreach (['TYPE_MAP', 'LIFECYCLE_MAP', 'LEAD_STATUS_MAP', 'industryOptions', 'currencyList', 'seenNames', 'seenDomains'] as $contract) {
    $mustContain($contract, $service, "Missing Account controlled-value or duplicate contract: {$contract}");
}

$mustContain("getNewEntity('Account')", $service, 'Account imports must check Account create access.');
$mustContain('checkEntityCreate($account)', $service, 'Account imports must enforce record creation ACL.');
$mustContain("getRDBRepository('Account')", $service, 'Existing company matching must use the tenant-scoped ORM.');
if (str_contains($service, 'INSERT INTO account')) {
    throw new RuntimeException('Account imports must not bypass tenant isolation with raw SQL.');
}

$routeMap = [];
foreach ($routes as $route) $routeMap[$route['route'] . ':' . $route['method']] = $route;
foreach ([
    '/Nexa/account-import/template:get',
    '/Nexa/account-import/preview:post',
    '/Nexa/account-import/preview-page:post',
    '/Nexa/account-import/confirm:post',
] as $route) {
    if (!isset($routeMap[$route]) || !empty($routeMap[$route]['noAuth'])) {
        throw new RuntimeException("Missing authenticated Account import route: {$route}");
    }
}

$mustContain("this.main('custom:views/account/import'", $controller, 'Account import must open the Nexa guided importer.');
if (str_contains($controller, "this.main('views/import/index'")) {
    throw new RuntimeException('Account import must not open the legacy generic importer.');
}

foreach ([
    'All .csv, .xlsx and .xls files are supported up to 65 MB.',
    'Download template',
    'Maximum rows for this import',
    'Check controlled values before uploading',
    'Account Type:',
    'Lifecycle Stage:',
    'Lead Status:',
    'Validate and preview',
    'Import accounts',
    'Back to Accounts',
    'Previous',
    'Next',
] as $label) {
    $mustContain($label, $template, "Missing Account import interface control: {$label}");
}

foreach ([
    "['csv', 'xlsx', 'xls']",
    'this.file.arrayBuffer()',
    'processData: false',
    'timeout: 300000',
    'renderExistingMatch',
    'loadPreviewPage',
    'result.errorDetails',
] as $behavior) {
    $mustContain($behavior, $view, "Missing Account import client behavior: {$behavior}");
}

echo "Account guided import contracts passed.\n";
