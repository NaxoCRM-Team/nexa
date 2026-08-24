<?php

namespace Espo\Custom\Tools\AccountImport;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Attachment;
use Espo\ORM\EntityManager;
use Espo\Tools\Import\Params;
use Espo\Tools\Import\Service as ImportService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Validates Account spreadsheets against one stable Nexa template before
 * passing normalized CSV to Espo's audited, tenant-scoped import engine.
 */
final class AccountImportService
{
    public const MAX_FILE_BYTES = 65 * 1024 * 1024;
    public const DEFAULT_ROW_LIMIT = 5000;
    public const MAX_ROW_LIMIT = 100000;

    /** @var string[] */
    public const SUPPORTED_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /** @var string[] */
    public const HEADER = [
        'company_name', 'website', 'phone', 'email', 'industry',
        'account_type', 'annual_revenue', 'currency', 'employees',
        'address_street', 'address_city', 'address_state',
        'address_postal_code', 'address_country', 'lifecycle_stage',
        'lead_status', 'description',
    ];

    /** @var string[] */
    private const ATTRIBUTE_LIST = [
        'name', 'website', 'phoneNumber', 'emailAddress', 'industry', 'type',
        'annualRevenue', 'annualRevenueCurrency', 'numberOfEmployees',
        'billingAddressStreet', 'billingAddressCity', 'billingAddressState',
        'billingAddressPostalCode', 'billingAddressCountry', 'lifecycleStage',
        'leadStatus', 'description',
    ];

    /** @var array<string, string> */
    private const TYPE_MAP = [
        'customer' => 'Customer',
        'investor' => 'Investor',
        'partner' => 'Partner',
        'reseller' => 'Reseller',
    ];

    /** @var array<string, string> */
    private const LIFECYCLE_MAP = [
        'subscriber' => 'Subscriber',
        'lead' => 'Lead',
        'marketing qualified lead' => 'MarketingQualifiedLead',
        'marketingqualifiedlead' => 'MarketingQualifiedLead',
        'mql' => 'MarketingQualifiedLead',
        'sales qualified lead' => 'SalesQualifiedLead',
        'salesqualifiedlead' => 'SalesQualifiedLead',
        'sql' => 'SalesQualifiedLead',
        'opportunity' => 'Opportunity',
        'customer' => 'Customer',
        'evangelist' => 'Evangelist',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    private const LEAD_STATUS_MAP = [
        'new' => 'New',
        'open' => 'Open',
        'in progress' => 'InProgress',
        'inprogress' => 'InProgress',
        'open deal' => 'OpenDeal',
        'opendeal' => 'OpenDeal',
        'unqualified' => 'Unqualified',
        'attempted to contact' => 'AttemptedToContact',
        'attemptedtocontact' => 'AttemptedToContact',
        'connected' => 'Connected',
        'bad timing' => 'BadTiming',
        'badtiming' => 'BadTiming',
    ];

    public function __construct(
        private ImportService $importService,
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
        private Acl $acl,
        private Metadata $metadata,
        private Config $config,
    ) {}

    /** @return array<string, mixed> */
    public function preview(string $contents, string $fileName, int $requestedRowLimit): array
    {
        $this->assertCreateAccess();
        $rowLimit = $this->normalizeRowLimit($requestedRowLimit);
        $result = $this->validate($contents, $fileName, $rowLimit);

        if ($result['valid']) {
            $result['attachmentId'] = $this->importService->uploadFile(
                (string) ($result['_normalizedContents'] ?? $contents)
            );
        }

        unset($result['_normalizedContents']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function confirm(string $attachmentId, int $requestedRowLimit): array
    {
        $this->assertCreateAccess();
        $rowLimit = $this->normalizeRowLimit($requestedRowLimit);
        $contents = $this->getAttachmentContents($attachmentId);
        $validation = $this->validate($contents, 'accounts.csv', $rowLimit);

        if (!$validation['valid']) {
            throw new BadRequest('The Account import file is no longer valid. Validate it again.');
        }

        $params = Params::create()
            ->withAction(Params::ACTION_CREATE)
            ->withDelimiter(',')
            ->withTextQualifier('"')
            ->withHeaderRow(true)
            ->withSkipDuplicateChecking(false);
        $result = $this->importService->import('Account', self::ATTRIBUTE_LIST, $attachmentId, $params);

        return [
            'id' => $result->getId(),
            'created' => $result->getCountCreated(),
            'updated' => $result->getCountUpdated(),
            'duplicates' => $result->getCountDuplicate(),
            'errors' => $result->getCountError(),
            'errorDetails' => $this->getImportErrorDetails($result->getId()),
        ];
    }

    /** @return array<string, mixed> */
    public function getPreviewPage(string $attachmentId, int $page, int $pageSize = 20): array
    {
        if ($page < 1 || $pageSize < 10 || $pageSize > 100) {
            throw new BadRequest('Preview page or page size is invalid.');
        }

        $stream = $this->openCsv($this->getAttachmentContents($attachmentId));
        $header = $this->readHeader($stream);
        if ($header !== self::HEADER) {
            fclose($stream);
            throw new BadRequest('The Account CSV header is no longer valid.');
        }

        $rows = [];
        $rowCount = 0;
        $start = ($page - 1) * $pageSize;
        $end = $start + $pageSize;

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($this->isEmptyRow($row)) continue;

            if ($rowCount >= $start && $rowCount < $end && count($row) === count(self::HEADER)) {
                $record = array_combine(self::HEADER, array_map(static fn ($value) => trim((string) $value), $row));
                if (is_array($record)) $rows[] = $record;
            }
            $rowCount++;
        }
        fclose($stream);

        $totalPages = max(1, (int) ceil($rowCount / $pageSize));
        if ($page > $totalPages) {
            throw new BadRequest('Preview page exceeds the available Account rows.');
        }

        return [
            'preview' => $rows,
            'page' => $page,
            'pageSize' => $pageSize,
            'rowCount' => $rowCount,
            'totalPages' => $totalPages,
        ];
    }

    public function getTemplate(): string
    {
        return implode(',', self::HEADER) . "\r\n";
    }

    /** @return array<string, mixed> */
    private function validate(string $contents, string $fileName, int $rowLimit): array
    {
        $errors = [];
        $preview = [];
        $normalizedRows = [];
        $rowCount = 0;
        $seenNames = [];
        $seenDomains = [];
        $extension = strtolower(pathinfo(trim($fileName), PATHINFO_EXTENSION));

        if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'Choose a .csv, .xlsx or .xls file.'];
        }
        $size = strlen($contents);
        if ($size === 0) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'The import file is empty.'];
        } elseif ($size > self::MAX_FILE_BYTES) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'The import file exceeds the 65 MB limit.'];
        }
        if ($errors) return $this->validationResult(false, 0, $rowLimit, [], $errors);

        if ($extension !== 'csv') {
            try {
                $contents = $this->convertSpreadsheetToCsv($contents, $extension, $rowLimit);
            } catch (\Throwable) {
                return $this->validationResult(false, 0, $rowLimit, [], [[
                    'row' => 0,
                    'field' => 'file',
                    'message' => 'The spreadsheet could not be read. Check that it is a valid, unencrypted Excel file.',
                ]]);
            }
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            return $this->validationResult(false, 0, $rowLimit, [], [[
                'row' => 0, 'field' => 'file', 'message' => 'Save text content using UTF-8 encoding.',
            ]]);
        }

        $stream = $this->openCsv($contents);
        if ($this->readHeader($stream) !== self::HEADER) {
            fclose($stream);
            return $this->validationResult(false, 0, $rowLimit, [], [[
                'row' => 1,
                'field' => 'header',
                'message' => 'The header does not match the Nexa Account template. Download a fresh template and keep its columns unchanged.',
            ]]);
        }

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($this->isEmptyRow($row)) continue;

            $rowCount++;
            $lineNumber = $rowCount + 1;
            if ($rowCount > $rowLimit) {
                $errors[] = ['row' => $lineNumber, 'field' => 'file', 'message' => "The file contains more than the selected {$rowLimit} row limit."];
                break;
            }
            if (count($row) !== count(self::HEADER)) {
                $errors[] = ['row' => $lineNumber, 'field' => 'row', 'message' => 'This row does not contain the expected 17 columns.'];
                continue;
            }

            $record = array_combine(self::HEADER, array_map(static fn ($value) => trim((string) $value), $row));
            if (!is_array($record)) continue;

            $this->validateAndNormalizeRecord($record, $lineNumber, $seenNames, $seenDomains, $errors);
            $normalizedRows[] = $record;
            if (count($preview) < 20) $preview[] = $record;

            if (count($errors) >= 100) {
                $errors[] = ['row' => $lineNumber, 'field' => 'file', 'message' => 'Validation stopped after 100 errors. Correct the file and try again.'];
                break;
            }
        }
        fclose($stream);

        if ($rowCount === 0) {
            $errors[] = ['row' => 2, 'field' => 'file', 'message' => 'Add at least one Account row below the header.'];
        }

        $result = $this->validationResult(!$errors, $rowCount, $rowLimit, $preview, $errors);
        $result['existingMatch'] = $this->matchExistingNames(array_keys($seenNames));
        $result['_normalizedContents'] = $this->encodeCsv($normalizedRows);

        return $result;
    }

    /**
     * @param array<string, string> $record
     * @param array<string, int> $seenNames
     * @param array<string, int> $seenDomains
     * @param array<int, array<string, int|string>> $errors
     */
    private function validateAndNormalizeRecord(
        array &$record,
        int $lineNumber,
        array &$seenNames,
        array &$seenDomains,
        array &$errors
    ): void {
        if ($record['company_name'] === '') {
            $errors[] = ['row' => $lineNumber, 'field' => 'company_name', 'message' => 'Company name is required.'];
        } else {
            $nameKey = mb_strtolower(preg_replace('/\s+/', ' ', $record['company_name']) ?? $record['company_name']);
            if (isset($seenNames[$nameKey])) {
                $errors[] = ['row' => $lineNumber, 'field' => 'company_name', 'message' => "This company name also appears on row {$seenNames[$nameKey]}."];
            } else {
                $seenNames[$nameKey] = $lineNumber;
            }
        }

        if ($record['website'] !== '') {
            $record['website'] = $this->normalizeWebsite($record['website']);
            if (filter_var($record['website'], FILTER_VALIDATE_URL) === false) {
                $errors[] = ['row' => $lineNumber, 'field' => 'website', 'message' => 'Enter a valid company website.'];
            } else {
                $domain = strtolower((string) parse_url($record['website'], PHP_URL_HOST));
                $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
                if ($domain !== '' && isset($seenDomains[$domain])) {
                    $errors[] = ['row' => $lineNumber, 'field' => 'website', 'message' => "This website domain also appears on row {$seenDomains[$domain]}."];
                } elseif ($domain !== '') {
                    $seenDomains[$domain] = $lineNumber;
                }
            }
        }

        if ($record['email'] !== '' && filter_var($record['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = ['row' => $lineNumber, 'field' => 'email', 'message' => 'Enter a valid company email address.'];
        }

        $record['industry'] = $this->normalizeOption($record['industry'], $this->industryOptions(), $lineNumber, 'industry', $errors);
        $record['account_type'] = $this->normalizeMapped($record['account_type'], self::TYPE_MAP, $lineNumber, 'account_type', $errors);
        $record['lifecycle_stage'] = $this->normalizeMapped($record['lifecycle_stage'], self::LIFECYCLE_MAP, $lineNumber, 'lifecycle_stage', $errors);
        $record['lead_status'] = $this->normalizeMapped($record['lead_status'], self::LEAD_STATUS_MAP, $lineNumber, 'lead_status', $errors);

        if ($record['annual_revenue'] !== '' && (!is_numeric($record['annual_revenue']) || (float) $record['annual_revenue'] < 0)) {
            $errors[] = ['row' => $lineNumber, 'field' => 'annual_revenue', 'message' => 'Annual revenue must be zero or a positive number.'];
        }
        if ($record['employees'] !== '' && (filter_var($record['employees'], FILTER_VALIDATE_INT) === false || (int) $record['employees'] < 0)) {
            $errors[] = ['row' => $lineNumber, 'field' => 'employees', 'message' => 'Employees must be zero or a positive whole number.'];
        }

        $record['currency'] = strtoupper($record['currency']);
        if ($record['annual_revenue'] !== '' && $record['currency'] === '') {
            $record['currency'] = (string) $this->config->get('defaultCurrency', 'USD');
        }
        $currencyList = $this->config->get('currencyList') ?? [];
        if ($record['currency'] !== '' && !in_array($record['currency'], $currencyList, true)) {
            $errors[] = ['row' => $lineNumber, 'field' => 'currency', 'message' => 'Use a currency enabled in this workspace.'];
        }
    }

    /** @param array<string, string> $map @param array<int, array<string, int|string>> $errors */
    private function normalizeMapped(string $value, array $map, int $row, string $field, array &$errors): string
    {
        if ($value === '') return '';
        $key = mb_strtolower(trim($value));
        if (isset($map[$key])) return $map[$key];

        $errors[] = ['row' => $row, 'field' => $field, 'message' => "The value '{$value}' is not supported. Use a controlled value shown on this page."];
        return $value;
    }

    /** @param string[] $options @param array<int, array<string, int|string>> $errors */
    private function normalizeOption(string $value, array $options, int $row, string $field, array &$errors): string
    {
        if ($value === '') return '';
        foreach ($options as $option) {
            if (mb_strtolower($option) === mb_strtolower($value)) return $option;
        }

        $errors[] = ['row' => $row, 'field' => $field, 'message' => "The value '{$value}' is not available in this workspace."];
        return $value;
    }

    /** @return string[] */
    private function industryOptions(): array
    {
        $options = $this->metadata->get(['entityDefs', 'Account', 'fields', 'industry', 'options']) ?? [];
        return array_values(array_filter($options, static fn ($value) => is_string($value) && $value !== ''));
    }

    private function normalizeWebsite(string $value): string
    {
        return preg_match('~^https?://~i', $value) ? $value : 'https://' . $value;
    }

    /** @param string[] $names @return array{matched: int, examples: string[]} */
    private function matchExistingNames(array $names): array
    {
        $matched = [];
        foreach (array_chunk($names, 250) as $chunk) {
            $collection = $this->entityManager->getRDBRepository('Account')
                ->select(['id', 'name'])
                ->where(['name' => $chunk])
                ->find();
            foreach ($collection as $account) {
                $matched[(string) $account->get('name')] = true;
            }
        }

        return ['matched' => count($matched), 'examples' => array_slice(array_keys($matched), 0, 10)];
    }

    /** @param array<int, array<string, string>> $rows */
    private function encodeCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new BadRequest('Could not prepare the normalized Account CSV.');
        fputcsv($stream, self::HEADER, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $header): string => $row[$header] ?? '', self::HEADER), ',', '"', '\\');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($contents)) throw new BadRequest('Could not prepare the normalized Account CSV.');
        return $contents;
    }

    private function convertSpreadsheetToCsv(string $contents, string $extension, int $rowLimit): string
    {
        $basePath = tempnam(sys_get_temp_dir(), 'nexa-account-import-');
        if ($basePath === false) throw new BadRequest('Could not prepare the spreadsheet for validation.');
        $filePath = $basePath . '.' . $extension;
        if (!rename($basePath, $filePath) || file_put_contents($filePath, $contents) === false) {
            @unlink($basePath);
            @unlink($filePath);
            throw new BadRequest('Could not prepare the spreadsheet for validation.');
        }

        $spreadsheet = null;
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $worksheetNames = $reader->listWorksheetNames($filePath);
            if (!$worksheetNames) throw new BadRequest('The spreadsheet does not contain a worksheet.');
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly($worksheetNames[0]);
            $reader->setReadFilter(new class($rowLimit + 2) implements IReadFilter {
                public function __construct(private int $maximumRow) {}
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row <= $this->maximumRow;
                }
            });
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = min($worksheet->getHighestDataRow(), $rowLimit + 2);
            $highestColumn = min(Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()), count(self::HEADER) + 1);
            $rows = [];
            for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
                $row = [];
                for ($columnIndex = 1; $columnIndex <= $highestColumn; $columnIndex++) {
                    $row[] = trim((string) $worksheet->getCellByColumnAndRow($columnIndex, $rowIndex)->getFormattedValue());
                }
                $rows[] = $row;
            }
            return $this->encodeRawCsvRows($rows);
        } finally {
            if ($spreadsheet !== null) $spreadsheet->disconnectWorksheets();
            @unlink($filePath);
        }
    }

    /** @param array<int, array<int, string>> $rows */
    private function encodeRawCsvRows(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new BadRequest('Could not convert the spreadsheet.');
        foreach ($rows as $row) fputcsv($stream, $row, ',', '"', '\\');
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($contents)) throw new BadRequest('Could not convert the spreadsheet.');
        return $contents;
    }

    /** @return array<int, array<string, int|string>> */
    private function getImportErrorDetails(string $importId): array
    {
        $details = [];
        $collection = $this->entityManager->getRDBRepository('ImportError')
            ->where(['importId' => $importId])->order('rowIndex')->limit(0, 50)->find();
        foreach ($collection as $error) {
            $failures = $error->get('validationFailures');
            $failure = is_array($failures) ? ($failures[0] ?? []) : [];
            $details[] = [
                'row' => ((int) $error->get('rowIndex')) + 1,
                'field' => is_array($failure) ? (string) ($failure['field'] ?? 'row') : 'row',
                'message' => $error->get('type') === 'Validation'
                    ? 'This value is not accepted by the Account field.'
                    : 'The row could not be imported.',
            ];
        }
        return $details;
    }

    private function assertCreateAccess(): void
    {
        $account = $this->entityManager->getNewEntity('Account');
        if (!$this->acl->checkEntityCreate($account)) {
            throw new Forbidden('Account creation access is required.');
        }
    }

    private function getAttachmentContents(string $attachmentId): string
    {
        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);
        if (!$attachment || $attachment->getRole() !== 'Import File') {
            throw new NotFound('Account import file not found.');
        }
        return $this->fileStorageManager->getContents($attachment);
    }

    /** @return resource */
    private function openCsv(string $contents)
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($stream === false) throw new BadRequest('Could not read the Account file.');
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }

    /** @param resource $stream @return string[] */
    private function readHeader($stream): array
    {
        $header = fgetcsv($stream, 0, ',', '"', '\\');
        return is_array($header) ? array_map(static fn ($value) => trim((string) $value), $header) : [];
    }

    /** @param string[] $row */
    private function isEmptyRow(array $row): bool
    {
        return count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0;
    }

    private function normalizeRowLimit(int $requested): int
    {
        if ($requested < 1 || $requested > self::MAX_ROW_LIMIT) {
            throw new BadRequest('Row limit must be between 1 and 100,000.');
        }
        return $requested;
    }

    /**
     * @param array<int, array<string, string>> $preview
     * @param array<int, array<string, int|string>> $errors
     * @return array<string, mixed>
     */
    private function validationResult(
        bool $valid,
        int $rowCount,
        int $rowLimit,
        array $preview,
        array $errors
    ): array {
        return [
            'valid' => $valid,
            'rowCount' => $rowCount,
            'rowLimit' => $rowLimit,
            'preview' => $preview,
            'previewPage' => 1,
            'previewPageSize' => 20,
            'previewTotalPages' => max(1, (int) ceil($rowCount / 20)),
            'errors' => $errors,
        ];
    }
}
