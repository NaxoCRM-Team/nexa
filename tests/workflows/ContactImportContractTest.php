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

require_once $root . '/espocrm/vendor/autoload.php';

$expectedHeader = "'first_name', 'last_name', 'email', 'phone', 'account_name'";

if (!str_contains((string) $service, $expectedHeader)) {
    throw new RuntimeException('The approved simple Contact CSV header is not present.');
}

foreach (['MAX_FILE_BYTES = 65 * 1024 * 1024', 'MAX_ROW_LIMIT = 100000', "SUPPORTED_EXTENSIONS = ['csv', 'xlsx', 'xls']", 'withHeaderRow(true)', 'convertSpreadsheetToCsv'] as $contract) {
    if (!str_contains((string) $service, $contract)) {
        throw new RuntimeException("Missing Contact import service contract: {$contract}");
    }
}

foreach (['SOURCE_MAP', 'LEAD_STATUS_MAP', 'normalizeRecord', 'encodeCsv', 'getImportErrorDetails'] as $contract) {
    if (!str_contains((string) $service, $contract)) {
        throw new RuntimeException("Missing Contact import normalization contract: {$contract}");
    }
}

foreach ([
    'private function prepareAccounts',
    "getRDBRepository('Account')",
    "getNewEntity('Account')",
    'checkEntityCreate($account)',
    'saveEntity($account)',
    "unset(\$result['_normalizedContents'], \$result['_normalizedRows'])",
    "'website' => trim(\$row['website'] ?? '')",
    "'billingAddressStreet' => trim(\$row['address_street'] ?? '')",
    "'billingAddressCountry' => trim(\$row['address_country'] ?? '')",
] as $contract) {
    if (!str_contains((string) $service, $contract)) {
        throw new RuntimeException("Missing tenant-scoped Account import contract: {$contract}");
    }
}

if (str_contains((string) $service, 'INSERT INTO account')) {
    throw new RuntimeException('Contact import must create Accounts through the tenant-aware ORM, not raw SQL.');
}

$serviceReflection = new ReflectionClass(Espo\Custom\Tools\ContactImport\ContactImportService::class);
$serviceInstance = $serviceReflection->newInstanceWithoutConstructor();
$normalizeRecord = $serviceReflection->getMethod('normalizeRecord');
$normalizeRecord->setAccessible(true);
$convertSpreadsheet = $serviceReflection->getMethod('convertSpreadsheetToCsv');
$convertSpreadsheet->setAccessible(true);

$assertNormalization = static function (
    string $source,
    string $leadStatus,
    string $expectedSource,
    string $expectedLeadStatus
) use ($normalizeRecord, $serviceInstance): void {
    $record = ['contact_source' => $source, 'lead_status' => $leadStatus];
    $errors = [];
    $arguments = [&$record, 2, &$errors];
    $normalizeRecord->invokeArgs($serviceInstance, $arguments);

    if ($errors || $record['contact_source'] !== $expectedSource || $record['lead_status'] !== $expectedLeadStatus) {
        throw new RuntimeException("Contact import normalization failed for {$source} / {$leadStatus}.");
    }
};

foreach ([
    ['Website', 'New', 'Direct', 'New'],
    ['LinkedIn', 'Qualified', 'Social Media', 'Open'],
    ['Instagram', 'Contacted', 'Social Media', 'Connected'],
    ['Trade Show', 'Proposal Sent', 'Event', 'OpenDeal'],
    ['Email Campaign', 'Won', 'Email Marketing', 'OpenDeal'],
    ['Cold Call', 'Bad Timing', 'Other', 'BadTiming'],
] as [$source, $leadStatus, $expectedSource, $expectedLeadStatus]) {
    $assertNormalization($source, $leadStatus, $expectedSource, $expectedLeadStatus);
}

$spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$spreadsheet->getActiveSheet()->fromArray([
    [
        'first_name', 'last_name', 'email', 'phone', 'account_name',
        'account_title', 'website', 'contact_source', 'lead_status',
        'address_street', 'address_city', 'address_state',
        'address_postal_code', 'address_country', 'linkedin_url',
        'facebook_url', 'instagram_url', 'x_url', 'tiktok_url', 'skype',
    ],
    [
        'Amina', 'Cole', 'amina@example.test', '+44 7700 900999', 'Example Ltd',
        'Director', 'https://example.test', 'Website', 'Contacted',
        '1 Test Road', 'London', 'Greater London', 'SW1A 1AA', 'United Kingdom',
        '', '', '', '', '', '',
    ],
]);

foreach (['xlsx' => 'Xlsx', 'xls' => 'Xls'] as $extension => $writerType) {
    $path = tempnam(sys_get_temp_dir(), 'nexa-import-test-');

    if ($path === false) {
        throw new RuntimeException('Could not create the spreadsheet test file.');
    }

    try {
        PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, $writerType)->save($path);
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new RuntimeException("Could not read the {$extension} test file.");
        }

        $csv = $convertSpreadsheet->invoke($serviceInstance, $contents, $extension, 5000);
        $lines = preg_split('/\r\n|\n|\r/', trim((string) $csv));
        $header = str_getcsv((string) ($lines[0] ?? ''));
        $row = str_getcsv((string) ($lines[1] ?? ''));

        if ($header[0] !== 'first_name' || $header[19] !== 'skype' || $row[2] !== 'amina@example.test') {
            throw new RuntimeException("The {$extension} reader did not preserve the Contact template.");
        }
    } finally {
        @unlink($path);
    }
}

$spreadsheet->disconnectWorksheets();

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

foreach (['All .csv, .xlsx and .xls files are supported up to 65 MB.', 'Drop a CSV or Excel file here', 'Download template', 'Maximum rows for this import', 'Check controlled values before uploading', 'Contact Source:', 'Lead Status:', 'Any other value will be highlighted during validation', 'Create missing accounts', 'within this workspace', 'Validate and preview', 'Import contacts', 'Back to Contacts', 'Previous', 'Next'] as $label) {
    if (!str_contains((string) $template, $label)) {
        throw new RuntimeException("Missing accessible Contact import control: {$label}");
    }
}

foreach (["['csv', 'xlsx', 'xls']", 'this.file.arrayBuffer()', 'processData: false', "timeout: 300000", 'createMissingAccounts:', 'result.accountsCreated', 'renderAccountMatch', 'loadPreviewPage', 'result.errorDetails'] as $behavior) {
    if (!str_contains((string) $view, $behavior)) {
        throw new RuntimeException("Missing Contact import client behavior: {$behavior}");
    }
}

echo "Contact import contract tests passed.\n";
