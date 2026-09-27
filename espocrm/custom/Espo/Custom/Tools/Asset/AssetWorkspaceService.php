<?php

namespace Espo\Custom\Tools\Asset;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\TenantFile\TenantImageLibrary;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Governed marketing assets layered on native Attachments and Documents. */
final class AssetWorkspaceService
{
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServices,
        private TenantImageLibrary $fileLibrary,
        private FileStorageManager $fileStorage,
        private TenantContextStore $tenantContextStore,
        private Acl $acl,
        private User $user,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $this->ensureProfiles($context);
        return $this->page($context, $input) + [
            'folders' => $this->folders($context),
            'limits' => ['imageBytes' => 8 * 1024 * 1024, 'fileBytes' => 25 * 1024 * 1024],
            'permissions' => [
                'create' => $this->acl->checkScope('Document', Table::ACTION_CREATE),
                'edit' => $this->acl->checkScope('Document', Table::ACTION_EDIT),
                'delete' => $this->acl->checkScope('Document', Table::ACTION_DELETE),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function upload(stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_CREATE);
        $context = $this->tenantContextStore->require();
        $metadata = $this->metadata($input);
        $this->requireFolder($context, $metadata['folderId']);
        $file = $this->fileLibrary->uploadFile(
            trim((string) ($input->fileName ?? '')), trim((string) ($input->mimeType ?? '')), trim((string) ($input->data ?? '')),
        );
        $document = $this->recordServices->get('Document')->create((object) [
            'name' => $metadata['displayName'], 'status' => 'Active', 'publishDate' => gmdate('Y-m-d'),
            'description' => $metadata['description'], 'fileId' => $file['id'], 'fileName' => $file['name'],
            'fileType' => $file['mimeType'], 'fileSize' => $file['size'], 'folderId' => $metadata['folderId'],
        ], CreateParams::create());
        $assetId = $this->uuid();
        $pdo = $this->entityManager->getPDO();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO nexa_asset_profile (id,tenant_id,service_id,current_attachment_id,document_id,folder_id,display_name,description,alt_text,locale,access_scope,tags_json,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([$assetId, $context->tenantId, $context->serviceId, $file['id'], $document->getId(), $metadata['folderId'], $metadata['displayName'], $metadata['description'], $metadata['altText'], $metadata['locale'], $metadata['accessScope'], $this->json($metadata['tags']), $this->user->getId(), $this->user->getId()]);
            $this->insertVersion($context, $assetId, 1, $file, $this->checksum((string) ($input->data ?? '')));
            $this->event($context, $assetId, 'upload');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $assetId, 'created' => true];
    }

    /** @return array<string, mixed> */
    public function update(string $id, stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $asset = $this->requireAsset($context, $id);
        $metadata = $this->metadata($input);
        $this->requireFolder($context, $metadata['folderId']);
        $statement = $this->entityManager->getPDO()->prepare('UPDATE nexa_asset_profile SET folder_id=?,display_name=?,description=?,alt_text=?,locale=?,access_scope=?,tags_json=?,modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?');
        $statement->execute([$metadata['folderId'], $metadata['displayName'], $metadata['description'], $metadata['altText'], $metadata['locale'], $metadata['accessScope'], $this->json($metadata['tags']), $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        if ($asset['document_id']) {
            $this->recordServices->get('Document')->update((string) $asset['document_id'], (object) ['name' => $metadata['displayName'], 'description' => $metadata['description'], 'folderId' => $metadata['folderId']], UpdateParams::create());
        }
        $this->event($context, $id, 'metadata_update');
        return ['id' => $id, 'updated' => true];
    }

    /** @return array<string, mixed> */
    public function replace(string $id, stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $asset = $this->requireAsset($context, $id);
        $file = $this->fileLibrary->uploadFile(trim((string) ($input->fileName ?? '')), trim((string) ($input->mimeType ?? '')), trim((string) ($input->data ?? '')));
        $version = ((int) $asset['version_number']) + 1;
        $this->entityManager->getTransactionManager()->run(function () use ($context, $asset, $file, $version, $id, $input): void {
            $pdo = $this->entityManager->getPDO();
            $statement = $pdo->prepare('UPDATE nexa_asset_profile SET current_attachment_id=?,version_number=?,modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?');
            $statement->execute([$file['id'], $version, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            $this->insertVersion($context, $id, $version, $file, $this->checksum((string) ($input->data ?? '')));
            if ($asset['document_id']) {
                $this->recordServices->get('Document')->update((string) $asset['document_id'], (object) ['fileId' => $file['id'], 'fileName' => $file['name'], 'fileType' => $file['mimeType'], 'fileSize' => $file['size']], UpdateParams::create());
            }
            $this->event($context, $id, 'upload');
        });
        return ['id' => $id, 'version' => $version];
    }

    /** @return array<string, mixed> */
    public function setArchived(string $id, bool $archived): array
    {
        $this->requireAccess(Table::ACTION_DELETE);
        $context = $this->tenantContextStore->require();
        $asset = $this->requireAsset($context, $id, true);
        $status = $archived ? 'archived' : 'active';
        $statement = $this->entityManager->getPDO()->prepare('UPDATE nexa_asset_profile SET status=?,archived_at=' . ($archived ? 'NOW(6)' : 'NULL') . ',modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?');
        $statement->execute([$status, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        if ($asset['document_id']) {
            $this->recordServices->get('Document')->update((string) $asset['document_id'], (object) ['status' => $archived ? 'Canceled' : 'Active'], UpdateParams::create());
        }
        $this->event($context, $id, $archived ? 'archive' : 'restore');
        return ['id' => $id, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function download(string $id): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $asset = $this->requireAsset($context, $id);
        $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getById((string) $asset['current_attachment_id']);
        if (!$attachment || $attachment->isBeingUploaded() || !$this->acl->checkEntity($attachment)) throw new NotFound('The asset file is unavailable.');
        $this->event($context, $id, 'download');
        return ['id' => $id, 'name' => $attachment->getName(), 'mimeType' => $attachment->getType(), 'data' => base64_encode($this->fileStorage->getContents($attachment))];
    }

    /** @return array<string, mixed> */
    private function page(TenantContext $context, stdClass $input): array
    {
        $offset = max(0, (int) ($input->offset ?? 0));
        $limit = max(1, min(self::MAX_PAGE_SIZE, (int) ($input->limit ?? 50)));
        $search = mb_substr(trim((string) ($input->search ?? '')), 0, 200);
        $status = in_array(($input->status ?? 'active'), ['active', 'archived', 'all'], true) ? (string) $input->status : 'active';
        $type = in_array(($input->type ?? 'all'), ['all', 'image', 'document', 'spreadsheet', 'archive'], true) ? (string) $input->type : 'all';
        $orders = ['name' => 'p.display_name', 'type' => 'a.type', 'size' => 'a.size', 'modifiedAt' => 'p.modified_at', 'downloads' => 'download_count'];
        $order = $orders[(string) ($input->orderBy ?? '')] ?? $orders['modifiedAt'];
        $direction = strtolower((string) ($input->direction ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $where = 'p.tenant_id=? AND p.service_id=?';
        $params = [$context->tenantId, $context->serviceId];
        if ($status !== 'all') { $where .= ' AND p.status=?'; $params[] = $status; }
        if ($search !== '') { $where .= " AND LOWER(CONCAT_WS(' ',p.display_name,p.description,p.alt_text,p.tags_json,a.name)) LIKE ?"; $params[] = '%' . mb_strtolower($search) . '%'; }
        $typeRules = ['image' => "a.type LIKE 'image/%'", 'document' => "(a.type LIKE '%pdf%' OR a.type LIKE '%word%' OR a.name REGEXP '\\.(docx?|pdf|rtf|txt)$')", 'spreadsheet' => "(a.type LIKE '%sheet%' OR a.type LIKE '%excel%' OR a.name REGEXP '\\.(xlsx?|csv)$')", 'archive' => "(a.type LIKE '%zip%' OR a.name REGEXP '\\.(zip)$')"];
        if (isset($typeRules[$type])) $where .= ' AND ' . $typeRules[$type];
        $count = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM nexa_asset_profile p INNER JOIN attachment a ON a.id=p.current_attachment_id AND a.tenant_id=p.tenant_id AND a.service_id=p.service_id AND a.deleted=0 WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->entityManager->getPDO()->prepare('SELECT p.*,a.name file_name,a.type mime_type,a.size file_size,f.name folder_name,COALESCE(NULLIF(TRIM(CONCAT_WS(\' \',u.first_name,u.last_name)),\'\'),u.user_name) modified_by_name,(SELECT COUNT(*) FROM nexa_asset_event e WHERE e.tenant_id=p.tenant_id AND e.service_id=p.service_id AND e.asset_id=p.id AND e.event_type=\'download\') download_count FROM nexa_asset_profile p INNER JOIN attachment a ON a.id=p.current_attachment_id AND a.tenant_id=p.tenant_id AND a.service_id=p.service_id AND a.deleted=0 LEFT JOIN document_folder f ON f.id=p.folder_id AND f.tenant_id=p.tenant_id AND f.service_id=p.service_id LEFT JOIN user u ON u.id=p.modified_by_id AND u.tenant_id=p.tenant_id AND u.service_id=p.service_id WHERE ' . $where . " ORDER BY {$order} {$direction},p.id {$direction} LIMIT {$limit} OFFSET {$offset}");
        $statement->execute($params);
        return ['list' => array_map(fn (array $row): array => $this->map($row), $statement->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    private function ensureProfiles(TenantContext $context): void
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT a.id,a.name,a.type,a.size,a.created_by_id,a.created_at,d.id document_id,d.name document_name,d.description,d.folder_id FROM attachment a LEFT JOIN document d ON d.file_id=a.id AND d.tenant_id=a.tenant_id AND d.service_id=a.service_id AND d.deleted=0 LEFT JOIN nexa_asset_profile p ON p.current_attachment_id=a.id AND p.tenant_id=a.tenant_id AND p.service_id=a.service_id WHERE a.tenant_id=? AND a.service_id=? AND a.deleted=0 AND a.is_being_uploaded=0 AND p.id IS NULL AND (d.id IS NOT NULL OR a.field IN ('nexaTenantAsset','nexaTenantFile')) LIMIT 500");
        $statement->execute([$context->tenantId, $context->serviceId]);
        $insert = $this->entityManager->getPDO()->prepare('INSERT IGNORE INTO nexa_asset_profile (id,tenant_id,service_id,current_attachment_id,document_id,folder_id,display_name,description,created_by_id,modified_by_id,created_at,modified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = $this->uuid();
            $insert->execute([$id, $context->tenantId, $context->serviceId, $row['id'], $row['document_id'], $row['folder_id'], $row['document_name'] ?: $row['name'], $row['description'], $row['created_by_id'], $row['created_by_id'], $row['created_at'], $row['created_at']]);
            $this->insertVersion($context, $id, 1, ['id' => $row['id'], 'name' => $row['name'], 'mimeType' => $row['type'], 'size' => (int) $row['size']], null);
        }
    }

    /** @return array<int, array{id: string, name: string}> */
    private function folders(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id,name FROM document_folder WHERE tenant_id=? AND service_id=? AND deleted=0 ORDER BY name LIMIT 500');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function metadata(stdClass $input): array
    {
        $name = trim((string) ($input->displayName ?? ''));
        if ($name === '' || mb_strlen($name) > 255) throw new BadRequest('Enter an asset name of 255 characters or fewer.');
        $description = trim((string) ($input->description ?? '')); $altText = trim((string) ($input->altText ?? ''));
        if (mb_strlen($description) > 1000 || mb_strlen($altText) > 500) throw new BadRequest('Asset description or alternative text is too long.');
        $locale = strtolower(trim((string) ($input->locale ?? 'en')));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $locale) !== 1) throw new BadRequest('Enter a valid content language.');
        $access = (string) ($input->accessScope ?? 'internal');
        if (!in_array($access, ['internal', 'public'], true)) throw new BadRequest('Choose a valid asset access scope.');
        $tags = array_values(array_unique(array_filter(array_map(static fn ($value): string => mb_substr(trim((string) $value), 0, 50), (array) ($input->tags ?? [])))));
        return ['displayName' => $name, 'description' => $description ?: null, 'altText' => $altText ?: null, 'locale' => $locale, 'accessScope' => $access, 'folderId' => trim((string) ($input->folderId ?? '')) ?: null, 'tags' => array_slice($tags, 0, 20)];
    }

    /** @return array<string, mixed> */
    private function requireAsset(TenantContext $context, string $id, bool $includeArchived = false): array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) throw new BadRequest('Select a valid asset.');
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=?' . ($includeArchived ? '' : " AND status='active'") . ' LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('The asset is unavailable.');
        return $row;
    }

    private function requireFolder(TenantContext $context, ?string $folderId): void
    {
        if ($folderId === null) return;
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $folderId) !== 1) throw new BadRequest('Choose a valid asset folder.');
        $statement = $this->entityManager->getPDO()->prepare('SELECT 1 FROM document_folder WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 LIMIT 1');
        $statement->execute([$folderId, $context->tenantId, $context->serviceId]);
        if (!$statement->fetchColumn()) throw new BadRequest('The selected folder is unavailable in this workspace.');
    }

    /** @param array{id: string, name: string, mimeType: string, size: int} $file */
    private function insertVersion(TenantContext $context, string $assetId, int $version, array $file, ?string $checksum): void
    {
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_asset_version (id,tenant_id,service_id,asset_id,attachment_id,version_number,file_name,mime_type,file_size,checksum_sha256,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $assetId, $file['id'], $version, $file['name'], $file['mimeType'], $file['size'], $checksum, $this->user->getId()]);
    }

    private function event(TenantContext $context, string $assetId, string $type): void
    {
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_asset_event (id,tenant_id,service_id,asset_id,event_type,actor_user_id) VALUES (?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $assetId, $type, $this->user->getId()]);
    }

    /** @return array<string, mixed> */
    private function map(array $row): array
    {
        return ['id' => $row['id'], 'attachmentId' => $row['current_attachment_id'], 'documentId' => $row['document_id'], 'name' => $row['display_name'], 'fileName' => $row['file_name'], 'mimeType' => $row['mime_type'], 'size' => (int) $row['file_size'], 'description' => $row['description'], 'altText' => $row['alt_text'], 'locale' => $row['locale'], 'accessScope' => $row['access_scope'], 'status' => $row['status'], 'tags' => json_decode((string) ($row['tags_json'] ?? '[]'), true) ?: [], 'version' => (int) $row['version_number'], 'folderId' => $row['folder_id'], 'folderName' => $row['folder_name'], 'downloads' => (int) $row['download_count'], 'modifiedAt' => $row['modified_at'], 'modifiedByName' => $row['modified_by_name']];
    }

    private function requireAccess(string $action): void { if (!$this->acl->checkScope('Document', $action)) throw new Forbidden('You do not have permission to manage assets.'); }
    private function checksum(string $encoded): ?string { if (str_contains($encoded, ',')) $encoded = substr($encoded, strpos($encoded, ',') + 1); $contents = base64_decode($encoded, true); return $contents === false ? null : hash('sha256', $contents); }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function uuid(): string { $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)); }
}
