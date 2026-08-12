<?php

namespace Espo\Custom\Tools\ContactImport;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Entities\Attachment;
use Espo\ORM\EntityManager;
use Espo\Tools\Import\Params;
use Espo\Tools\Import\Service as ImportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Converts supported tabular files into one narrow Contact CSV contract before
 * handing a tenant-scoped attachment to Espo's audited import engine.
 */
class ContactImportService
{
    public const MAX_FILE_BYTES = 65 * 1024 * 1024;
    public const DEFAULT_ROW_LIMIT = 5000;
    public const MAX_ROW_LIMIT = 100000;

    /** @var string[] */
    public const SUPPORTED_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /** @var string[] */
    public const HEADER = [
        'first_name', 'last_name', 'email', 'phone', 'account_name',
        'account_title', 'website', 'contact_source', 'lead_status',
        'address_street', 'address_city', 'address_state',
        'address_postal_code', 'address_country', 'linkedin_url',
        'facebook_url', 'instagram_url', 'x_url', 'tiktok_url', 'skype',
    ];

    /** @var string[] */
    private const ATTRIBUTE_LIST = [
        'firstName', 'lastName', 'emailAddress', 'phoneNumber', 'accountName',
        'title', 'website', 'source', 'leadStatus', 'addressStreet',
        'addressCity', 'addressState', 'addressPostalCode', 'addressCountry',
        'linkedinUrl', 'facebookUrl', 'instagramUrl', 'xUrl', 'tiktokUrl',
        'skypeName',
    ];

    /** @var string[] */
    private const URL_COLUMNS = [
        'website', 'linkedin_url', 'facebook_url', 'instagram_url', 'x_url',
        'tiktok_url',
    ];

    /** @var array<string, string> */
    private const SOURCE_MAP = [
        'direct' => 'Direct',
        'website' => 'Direct',
        'organic search' => 'Organic Search',
        'paid search' => 'Paid Search',
        'email marketing' => 'Email Marketing',
        'email campaign' => 'Email Marketing',
        'social media' => 'Social Media',
        'linkedin' => 'Social Media',
        'facebook' => 'Social Media',
        'instagram' => 'Social Media',
        'x' => 'Social Media',
        'twitter' => 'Social Media',
        'referral' => 'Referral',
        'partner' => 'Partner',
        'event' => 'Event',
        'trade show' => 'Event',
        'import' => 'Import',
        'cold call' => 'Other',
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
        'contacted' => 'Connected',
        'qualified' => 'Open',
        'proposal sent' => 'OpenDeal',
        'won' => 'OpenDeal',
    ];

    public function __construct(
        private ImportService $importService,
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
        private Acl $acl,
    ) {}

    /** @return array<string, mixed> */
    public function preview(string $contents, string $fileName, int $requestedRowLimit): array
    {
        $rowLimit = $this->normalizeRowLimit($requestedRowLimit);
        $result = $this->validate($contents, $fileName, $rowLimit);

        if ($result['valid']) {
            $normalizedContents = (string) ($result['_normalizedContents'] ?? $contents);
            $result['attachmentId'] = $this->importService->uploadFile($normalizedContents);
        }

        unset($result['_normalizedContents'], $result['_normalizedRows']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function confirm(
        string $attachmentId,
        int $requestedRowLimit,
        bool $createMissingAccounts = true,
    ): array
    {
        $rowLimit = $this->normalizeRowLimit($requestedRowLimit);
        $contents = $this->getAttachmentContents($attachmentId);
        $validation = $this->validate($contents, 'contacts.csv', $rowLimit);

        if (!$validation['valid']) {
            throw new BadRequest('The Contact import file is no longer valid. Validate it again.');
        }

        $accountResult = $this->prepareAccounts(
            $validation['_normalizedRows'] ?? [],
            $createMissingAccounts,
        );
        $params = Params::create()
            ->withAction(Params::ACTION_CREATE)
            ->withDelimiter(',')
            ->withTextQualifier('"')
            ->withHeaderRow(true)
            ->withSkipDuplicateChecking(false);

        $result = $this->importService->import(
            'Contact', self::ATTRIBUTE_LIST, $attachmentId, $params
        );

        return [
            'id' => $result->getId(),
            'created' => $result->getCountCreated(),
            'updated' => $result->getCountUpdated(),
            'duplicates' => $result->getCountDuplicate(),
            'errors' => $result->getCountError(),
            'errorDetails' => $this->getImportErrorDetails($result->getId()),
            'accountsMatched' => $accountResult['matched'],
            'accountsCreated' => $accountResult['created'],
            'accountsUnlinked' => $accountResult['unlinked'],
        ];
    }

    /**
     * Returns one bounded preview page from a previously validated, tenant-owned
     * attachment. Keeping paging on the server avoids rendering huge CSV files.
     *
     * @return array<string, mixed>
     */
    public function getPreviewPage(string $attachmentId, int $page, int $pageSize = 20): array
    {
        if ($page < 1) {
            throw new BadRequest('Preview page must be at least 1.');
        }

        if ($pageSize < 10 || $pageSize > 100) {
            throw new BadRequest('Preview page size must be between 10 and 100.');
        }

        $contents = $this->getAttachmentContents($attachmentId);

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');

        if ($stream === false) {
            throw new BadRequest('Could not read the Contact preview.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '\\');
        $header = is_array($header) ? array_map(static fn ($value) => trim((string) $value), $header) : [];

        if ($header !== self::HEADER) {
            fclose($stream);
            throw new BadRequest('The Contact CSV header is no longer valid.');
        }

        $rows = [];
        $rowCount = 0;
        $start = ($page - 1) * $pageSize;
        $end = $start + $pageSize;

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            if ($rowCount >= $start && $rowCount < $end && count($row) === count(self::HEADER)) {
                $record = array_combine(
                    self::HEADER,
                    array_map(static fn ($value) => trim((string) $value), $row)
                );

                if (is_array($record)) {
                    $rows[] = $record;
                }
            }

            $rowCount++;
        }

        fclose($stream);

        $totalPages = max(1, (int) ceil($rowCount / $pageSize));

        if ($page > $totalPages) {
            throw new BadRequest('Preview page exceeds the available Contact rows.');
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
        $rowCount = 0;
        $seenEmails = [];
        $accountNames = [];
        $normalizedRows = [];

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

        if ($errors) {
            return $this->validationResult(false, $rowCount, $rowLimit, $preview, $errors);
        }

        if ($extension !== 'csv') {
            try {
                $contents = $this->convertSpreadsheetToCsv($contents, $extension, $rowLimit);
            } catch (\Throwable) {
                $errors[] = [
                    'row' => 0,
                    'field' => 'file',
                    'message' => 'The spreadsheet could not be read. Check that it is a valid, unencrypted Excel file.',
                ];

                return $this->validationResult(false, $rowCount, $rowLimit, $preview, $errors);
            }
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'Save text content using UTF-8 encoding.'];

            return $this->validationResult(false, $rowCount, $rowLimit, $preview, $errors);
        }

        // A temporary stream lets PHP's CSV parser correctly handle quoted values and newlines.
        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');

        if ($stream === false) {
            throw new BadRequest('Could not read the CSV file.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '\\');
        $header = is_array($header) ? array_map(static fn ($value) => trim((string) $value), $header) : [];

        if ($header !== self::HEADER) {
            $errors[] = [
                'row' => 1,
                'field' => 'header',
                'message' => 'The header does not match the Nexa Contact template. Download a fresh template and keep its columns unchanged.',
            ];
            fclose($stream);

            return $this->validationResult(false, $rowCount, $rowLimit, $preview, $errors);
        }

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rowCount++;
            $lineNumber = $rowCount + 1;

            if ($rowCount > $rowLimit) {
                $errors[] = [
                    'row' => $lineNumber,
                    'field' => 'file',
                    'message' => "The file contains more than the selected {$rowLimit} row limit.",
                ];
                break;
            }

            if (count($row) !== count(self::HEADER)) {
                $errors[] = [
                    'row' => $lineNumber,
                    'field' => 'row',
                    'message' => 'This row does not contain the expected 20 columns.',
                ];
                continue;
            }

            $record = array_combine(self::HEADER, array_map(static fn ($value) => trim((string) $value), $row));

            if (!is_array($record)) {
                continue;
            }

            $this->validateRecord($record, $lineNumber, $seenEmails, $errors);
            $this->normalizeRecord($record, $lineNumber, $errors);
            $normalizedRows[] = $record;

            if ($record['account_name'] !== '') {
                $accountNames[$record['account_name']] = true;
            }

            if (count($preview) < 20) {
                $preview[] = $record;
            }

            if (count($errors) >= 100) {
                $errors[] = [
                    'row' => $lineNumber,
                    'field' => 'file',
                    'message' => 'Validation stopped after 100 errors. Correct the file and try again.',
                ];
                break;
            }
        }

        fclose($stream);

        if ($rowCount === 0) {
            $errors[] = ['row' => 2, 'field' => 'file', 'message' => 'Add at least one Contact row below the header.'];
        }

        $accountMatch = $this->matchAccounts(array_keys($accountNames));
        $result = $this->validationResult(!$errors, $rowCount, $rowLimit, $preview, $errors);
        $result['accountMatch'] = $accountMatch;
        $result['_normalizedContents'] = $this->encodeCsv($normalizedRows);
        $result['_normalizedRows'] = $normalizedRows;

        return $result;
    }

    /**
     * @param array<string, string> $record
     * @param array<string, int> $seenEmails
     * @param array<int, array<string, int|string>> $errors
     */
    private function validateRecord(array $record, int $lineNumber, array &$seenEmails, array &$errors): void
    {
        foreach (['last_name', 'email'] as $field) {
            if ($record[$field] === '') {
                $errors[] = ['row' => $lineNumber, 'field' => $field, 'message' => 'A value is required.'];
            }
        }

        $email = strtolower($record['email']);

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = ['row' => $lineNumber, 'field' => 'email', 'message' => 'Enter a valid email address.'];
        } elseif ($email !== '' && isset($seenEmails[$email])) {
            $errors[] = [
                'row' => $lineNumber,
                'field' => 'email',
                'message' => "This email also appears on row {$seenEmails[$email]}.",
            ];
        } elseif ($email !== '') {
            $seenEmails[$email] = $lineNumber;
        }

        foreach (self::URL_COLUMNS as $field) {
            $value = $record[$field];

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[] = ['row' => $lineNumber, 'field' => $field, 'message' => 'Enter a complete URL including https://.'];
            }
        }
    }

    /**
     * Converts familiar CRM labels into the canonical enum values persisted by
     * Nexa. Unknown labels fail during preview instead of becoming opaque
     * native-import errors after confirmation.
     *
     * @param array<string, string> $record
     * @param array<int, array<string, int|string>> $errors
     */
    private function normalizeRecord(array &$record, int $lineNumber, array &$errors): void
    {
        $record['contact_source'] = $this->normalizeEnumValue(
            $record['contact_source'],
            self::SOURCE_MAP,
            $lineNumber,
            'contact_source',
            $errors
        );
        $record['lead_status'] = $this->normalizeEnumValue(
            $record['lead_status'],
            self::LEAD_STATUS_MAP,
            $lineNumber,
            'lead_status',
            $errors
        );
    }

    /**
     * @param array<string, string> $map
     * @param array<int, array<string, int|string>> $errors
     */
    private function normalizeEnumValue(
        string $value,
        array $map,
        int $lineNumber,
        string $field,
        array &$errors
    ): string {
        if ($value === '') {
            return '';
        }

        $key = strtolower(trim($value));

        if (isset($map[$key])) {
            return $map[$key];
        }

        $errors[] = [
            'row' => $lineNumber,
            'field' => $field,
            'message' => "The value '{$value}' is not supported. Use a value from the Contact form.",
        ];

        return $value;
    }

    /** @param array<int, array<string, string>> $rows */
    private function encodeCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new BadRequest('Could not prepare the normalized Contact CSV.');
        }

        fputcsv($stream, self::HEADER, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv(
                $stream,
                array_map(static fn (string $header): string => $row[$header] ?? '', self::HEADER),
                ',',
                '"',
                '\\'
            );
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if (!is_string($contents)) {
            throw new BadRequest('Could not prepare the normalized Contact CSV.');
        }

        return $contents;
    }

    /**
     * Reads only the first worksheet and only enough rows to enforce the
     * selected limit. The result then follows the same validation and import
     * path as a CSV upload.
     */
    private function convertSpreadsheetToCsv(
        string $contents,
        string $extension,
        int $rowLimit
    ): string {
        $basePath = tempnam(sys_get_temp_dir(), 'nexa-contact-import-');

        if ($basePath === false) {
            throw new BadRequest('Could not prepare the spreadsheet for validation.');
        }

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

            if (!$worksheetNames) {
                throw new BadRequest('The spreadsheet does not contain a worksheet.');
            }

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
            $highestColumn = min(
                Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
                count(self::HEADER) + 1
            );
            $rows = [];

            for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
                $row = [];

                for ($columnIndex = 1; $columnIndex <= $highestColumn; $columnIndex++) {
                    $row[] = trim((string) $worksheet
                        ->getCellByColumnAndRow($columnIndex, $rowIndex)
                        ->getFormattedValue());
                }

                $rows[] = $row;
            }

            return $this->encodeRawCsvRows($rows);
        } finally {
            if ($spreadsheet !== null) {
                $spreadsheet->disconnectWorksheets();
            }

            @unlink($filePath);
        }
    }

    /** @param array<int, array<int, string>> $rows */
    private function encodeRawCsvRows(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new BadRequest('Could not convert the spreadsheet.');
        }

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '\\');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if (!is_string($contents)) {
            throw new BadRequest('Could not convert the spreadsheet.');
        }

        return $contents;
    }

    /** @return array<int, array<string, int|string>> */
    private function getImportErrorDetails(string $importId): array
    {
        $details = [];
        $collection = $this->entityManager
            ->getRDBRepository('ImportError')
            ->where(['importId' => $importId])
            ->order('rowIndex')
            ->limit(0, 50)
            ->find();

        foreach ($collection as $error) {
            $failures = $error->get('validationFailures');
            $failure = is_array($failures) ? ($failures[0] ?? []) : [];
            $field = is_array($failure) ? (string) ($failure['field'] ?? 'row') : 'row';

            $details[] = [
                'row' => ((int) $error->get('rowIndex')) + 1,
                'field' => $field,
                'message' => $error->get('type') === 'Validation'
                    ? 'This value is not accepted by the Contact field.'
                    : 'The row could not be imported.',
            ];
        }

        return $details;
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

    private function getAttachmentContents(string $attachmentId): string
    {
        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

        if (!$attachment || $attachment->getRole() !== 'Import File') {
            throw new NotFound('Contact import file not found.');
        }

        return $this->fileStorageManager->getContents($attachment);
    }

    /**
     * Resolves companies only through the tenant-aware ORM. The central query
     * processor scopes both lookup and creation to the active tenant/service.
     * Existing Accounts are linked but never changed by a Contact import.
     *
     * @param array<int, array<string, string>> $rows
     * @return array{matched: int, created: int, unlinked: int}
     */
    private function prepareAccounts(array $rows, bool $createMissing): array
    {
        $companyRows = [];

        foreach ($rows as $row) {
            $name = trim($row['account_name'] ?? '');

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            $companyRows[$key] ??= $row;
        }

        if (!$companyRows) {
            return ['matched' => 0, 'created' => 0, 'unlinked' => 0];
        }

        $existing = [];
        $names = array_map(
            static fn (array $row): string => trim($row['account_name']),
            array_values($companyRows),
        );

        foreach (array_chunk($names, 500) as $nameBatch) {
            $accounts = $this->entityManager
                ->getRDBRepository('Account')
                ->where(['name' => $nameBatch])
                ->find();

            foreach ($accounts as $account) {
                $name = trim((string) $account->get('name'));

                if ($name !== '') {
                    $existing[mb_strtolower($name)] = true;
                }
            }
        }

        $missing = array_diff_key($companyRows, $existing);

        if (!$missing || !$createMissing) {
            return [
                'matched' => count($existing),
                'created' => 0,
                'unlinked' => $createMissing ? 0 : count($missing),
            ];
        }

        if (!$this->acl->checkScope('Account', Table::ACTION_CREATE)) {
            throw new Forbidden('You do not have permission to create missing Accounts.');
        }

        $created = 0;

        foreach ($missing as $row) {
            $account = $this->entityManager->getNewEntity('Account');
            $account->setMultiple(array_filter([
                'name' => trim($row['account_name']),
                'website' => trim($row['website'] ?? ''),
                'billingAddressStreet' => trim($row['address_street'] ?? ''),
                'billingAddressCity' => trim($row['address_city'] ?? ''),
                'billingAddressState' => trim($row['address_state'] ?? ''),
                'billingAddressPostalCode' => trim($row['address_postal_code'] ?? ''),
                'billingAddressCountry' => trim($row['address_country'] ?? ''),
            ], static fn (string $value): bool => $value !== ''));

            if (!$this->acl->checkEntityCreate($account)) {
                throw new Forbidden('You do not have permission to create one or more imported Accounts.');
            }

            $this->entityManager->saveEntity($account);
            $created++;
        }

        return [
            'matched' => count($existing),
            'created' => $created,
            'unlinked' => 0,
        ];
    }

    /**
     * Existing accounts are matched inside the active TenantContext. Unmatched
     * names are reported during preview so the importer can review which
     * companies will be created during confirmation.
     *
     * @param string[] $names
     * @return array<string, mixed>
     */
    private function matchAccounts(array $names): array
    {
        if (!$names) {
            return ['requested' => 0, 'matched' => 0, 'unmatched' => []];
        }

        $matched = [];
        // Keep SQL IN clauses bounded even when the selected import limit is high.
        foreach (array_chunk($names, 500) as $nameBatch) {
            $collection = $this->entityManager
                ->getRDBRepository('Account')
                ->where(['name' => $nameBatch])
                ->find();

            foreach ($collection as $account) {
                $name = $account->get('name');

                if (is_string($name)) {
                    $matched[$name] = true;
                }
            }
        }

        $unmatched = array_values(array_filter(
            $names,
            static fn (string $name): bool => !isset($matched[$name])
        ));

        return [
            'requested' => count($names),
            'matched' => count($matched),
            'unmatched' => array_slice($unmatched, 0, 20),
            'unmatchedCount' => count($unmatched),
        ];
    }

    /**
     * @param array<int, array<string, string>> $preview
     * @param array<int, array<string, int|string>> $errors
     * @return array<string, mixed>
     */
    private function validationResult(bool $valid, int $rowCount, int $rowLimit, array $preview, array $errors): array
    {
        return [
            'valid' => $valid,
            'rowCount' => $rowCount,
            'rowLimit' => $rowLimit,
            'maxRowLimit' => self::MAX_ROW_LIMIT,
            'maxFileBytes' => self::MAX_FILE_BYTES,
            'preview' => $preview,
            'previewPage' => 1,
            'previewPageSize' => 20,
            'previewTotalPages' => max(1, (int) ceil($rowCount / 20)),
            'errors' => $errors,
        ];
    }
}
