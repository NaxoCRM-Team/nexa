<?php

namespace Espo\Custom\Tools\TenantFile;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Attachment;

/**
 * Lists and stores reusable rich-content files in the active tenant and service scope.
 * TenantQueryProcessor adds the trusted tenant and service conditions to the
 * Attachment query, while CloudflareR2 keeps the corresponding bytes under
 * the same tenant prefix in object storage.
 */
final class TenantImageLibrary
{
    private const MAX_SCAN_SIZE = 500;
    private const DEFAULT_PAGE_SIZE = 12;
    private const MAX_PAGE_SIZE = 48;
    private const MAX_IMAGE_SIZE = 8 * 1024 * 1024;
    private const MAX_FILE_SIZE = 25 * 1024 * 1024;

    /** @var array<string, string[]> */
    private const FILE_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'rtf' => ['application/rtf', 'text/rtf', 'text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
    ) {}

    /** @return array{list: array<int, array<string, mixed>>, total: int, offset: int, limit: int} */
    public function getPage(string $search, int $offset, int $limit): array
    {
        if (!$this->acl->checkScope('Note', Table::ACTION_CREATE)) {
            throw new Forbidden('You do not have permission to use tenant files.');
        }

        $offset = max(0, $offset);
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit ?: self::DEFAULT_PAGE_SIZE));
        $needle = mb_strtolower(trim($search));
        $items = [];

        $attachments = $this->entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where([
                'role' => Attachment::ROLE_INLINE_ATTACHMENT,
                'isBeingUploaded' => false,
            ])
            ->order('createdAt', 'DESC')
            ->limit(self::MAX_SCAN_SIZE)
            ->find();

        foreach ($attachments as $attachment) {
            $mimeType = strtolower(trim((string) $attachment->getType()));
            $name = trim((string) $attachment->getName());

            if (!str_starts_with($mimeType, 'image/')) {
                continue;
            }
            if ($needle !== '' && !str_contains(mb_strtolower($name), $needle)) {
                continue;
            }

            $items[] = [
                'id' => $attachment->getId(),
                'name' => $name !== '' ? $name : 'Untitled image',
                'mimeType' => $mimeType,
                'size' => $attachment->getSize(),
                'createdAt' => $attachment->get('createdAt'),
                'createdByName' => $attachment->get('createdByName'),
            ];
        }

        return [
            'list' => array_values(array_slice($items, $offset, $limit)),
            'total' => count($items),
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    /** @return array{list: array<int, array<string, mixed>>, total: int, offset: int, limit: int} */
    public function getFilePage(string $search, int $offset, int $limit): array
    {
        if (!$this->acl->checkScope('Note', Table::ACTION_CREATE)) {
            throw new Forbidden('You do not have permission to use tenant files.');
        }

        $offset = max(0, $offset);
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit ?: self::DEFAULT_PAGE_SIZE));
        $needle = mb_strtolower(trim($search));
        $items = [];
        $attachments = $this->entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where(['role' => Attachment::ROLE_INLINE_ATTACHMENT, 'isBeingUploaded' => false])
            ->order('createdAt', 'DESC')
            ->limit(self::MAX_SCAN_SIZE)
            ->find();

        foreach ($attachments as $attachment) {
            $name = trim((string) $attachment->getName());
            if ($needle !== '' && !str_contains(mb_strtolower($name), $needle)) {
                continue;
            }
            $items[] = [
                'id' => $attachment->getId(),
                'name' => $name !== '' ? $name : 'Untitled file',
                'mimeType' => strtolower(trim((string) $attachment->getType())),
                'size' => $attachment->getSize(),
                'createdAt' => $attachment->get('createdAt'),
                'createdByName' => $attachment->get('createdByName'),
            ];
        }

        return [
            'list' => array_values(array_slice($items, $offset, $limit)),
            'total' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'maxFileSize' => self::MAX_FILE_SIZE,
            'maxImageSize' => self::MAX_IMAGE_SIZE,
        ];
    }

    /** @return array{id: string, name: string, mimeType: string, size: int} */
    public function upload(string $name, string $declaredType, string $encodedData): array
    {
        if (!$this->acl->checkScope('Note', Table::ACTION_CREATE)) {
            throw new Forbidden('You do not have permission to upload tenant files.');
        }

        if (str_contains($encodedData, ',')) {
            $encodedData = substr($encodedData, strpos($encodedData, ',') + 1);
        }
        $contents = base64_decode($encodedData, true);
        if ($contents === false || $contents === '') {
            throw new BadRequest('The image payload is invalid.');
        }
        if (strlen($contents) > self::MAX_IMAGE_SIZE) {
            throw new BadRequest('Images cannot be larger than 8 MB.');
        }

        $detectedType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
        $extensionMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($extensionMap[$detectedType]) || strtolower(trim($declaredType)) !== $detectedType) {
            throw new BadRequest('Choose a valid PNG, JPG, GIF or WebP image.');
        }

        $baseName = pathinfo(trim($name), PATHINFO_FILENAME);
        $baseName = trim((string) preg_replace('/[^a-zA-Z0-9._ -]+/', '-', $baseName), '. -');
        $baseName = mb_substr($baseName !== '' ? $baseName : 'tenant-image', 0, 100);
        $safeName = $baseName . '.' . $extensionMap[$detectedType];

        $attachment = $this->entityManager
            ->getRDBRepositoryByClass(Attachment::class)
            ->getNew();
        $attachment
            ->setName($safeName)
            ->setType($detectedType)
            ->setRole(Attachment::ROLE_INLINE_ATTACHMENT)
            ->setTargetField('nexaTenantAsset')
            ->setSize(strlen($contents))
            ->setContents($contents)
            ->set('global', true);
        $this->entityManager->saveEntity($attachment, [SaveOption::SILENT => true]);

        return [
            'id' => $attachment->getId(),
            'name' => $safeName,
            'mimeType' => $detectedType,
            'size' => strlen($contents),
        ];
    }

    /** @return array{id: string, name: string, mimeType: string, size: int} */
    public function uploadFile(string $name, string $declaredType, string $encodedData): array
    {
        if (!$this->acl->checkScope('Note', Table::ACTION_CREATE)) {
            throw new Forbidden('You do not have permission to upload tenant files.');
        }
        if (str_contains($encodedData, ',')) {
            $encodedData = substr($encodedData, strpos($encodedData, ',') + 1);
        }
        $contents = base64_decode($encodedData, true);
        if ($contents === false || $contents === '') {
            throw new BadRequest('The file payload is invalid.');
        }

        $extension = strtolower(pathinfo(trim($name), PATHINFO_EXTENSION));
        $detectedType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
        if (!isset(self::FILE_TYPES[$extension]) || !in_array($detectedType, self::FILE_TYPES[$extension], true)) {
            throw new BadRequest('Choose a supported image, PDF, Office, CSV, TXT, RTF or ZIP file.');
        }
        $limit = str_starts_with($detectedType, 'image/') ? self::MAX_IMAGE_SIZE : self::MAX_FILE_SIZE;
        if (strlen($contents) > $limit) {
            throw new BadRequest(str_starts_with($detectedType, 'image/')
                ? 'Images cannot be larger than 8 MB.'
                : 'Files cannot be larger than 25 MB.');
        }

        $baseName = pathinfo(trim($name), PATHINFO_FILENAME);
        $baseName = trim((string) preg_replace('/[^a-zA-Z0-9._ -]+/', '-', $baseName), '. -');
        $safeName = mb_substr($baseName !== '' ? $baseName : 'tenant-file', 0, 100) . '.' . $extension;
        $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();
        $attachment
            ->setName($safeName)
            ->setType($detectedType)
            ->setRole(Attachment::ROLE_INLINE_ATTACHMENT)
            ->setTargetField('nexaTenantFile')
            ->setSize(strlen($contents))
            ->setContents($contents)
            ->set('global', true);
        $this->entityManager->saveEntity($attachment, [SaveOption::SILENT => true]);

        return ['id' => $attachment->getId(), 'name' => $safeName, 'mimeType' => $detectedType, 'size' => strlen($contents)];
    }
}
