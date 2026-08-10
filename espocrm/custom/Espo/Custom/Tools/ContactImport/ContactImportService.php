<?php

namespace Espo\Custom\Tools\ContactImport;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Entities\Attachment;
use Espo\ORM\EntityManager;
use Espo\Tools\Import\Params;
use Espo\Tools\Import\Service as ImportService;

/**
 * Validates the narrow Contact CSV contract before handing a tenant-scoped
 * attachment to Espo's standard, audited import engine.
 */
class ContactImportService
{
    public const MAX_FILE_BYTES = 65 * 1024 * 1024;
    public const DEFAULT_ROW_LIMIT = 5000;
    public const MAX_ROW_LIMIT = 100000;

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

    public function __construct(
        private ImportService $importService,
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager
    ) {}

    /** @return array<string, mixed> */
    public function preview(string $contents, string $fileName, int $requestedRowLimit): array
    {
        $rowLimit = $this->normalizeRowLimit($requestedRowLimit);
        $result = $this->validate($contents, $fileName, $rowLimit);

        if ($result['valid']) {
            $result['attachmentId'] = $this->importService->uploadFile($contents);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function confirm(string $attachmentId, int $requestedRowLimit): array
    {
        $rowLimit = $this->normalizeRowLimit($requestedRowLimit);
        $contents = $this->getAttachmentContents($attachmentId);
        $validation = $this->validate($contents, 'contacts.csv', $rowLimit);

        if (!$validation['valid']) {
            throw new BadRequest('The Contact CSV is no longer valid. Validate it again.');
        }

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

        if (!str_ends_with(strtolower(trim($fileName)), '.csv')) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'Choose a file with a .csv extension.'];
        }

        $size = strlen($contents);

        if ($size === 0) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'The CSV file is empty.'];
        } elseif ($size > self::MAX_FILE_BYTES) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'The CSV file exceeds the 65 MB limit.'];
        }

        if ($errors) {
            return $this->validationResult(false, $rowCount, $rowLimit, $preview, $errors);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $errors[] = ['row' => 0, 'field' => 'file', 'message' => 'Save the CSV using UTF-8 encoding.'];

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
     * Existing accounts are matched inside the active TenantContext. Unmatched
     * names are reported before import and remain unlinked rather than creating
     * company records implicitly from potentially misspelled CSV values.
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
