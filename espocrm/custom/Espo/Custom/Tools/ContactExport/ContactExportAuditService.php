<?php

namespace Espo\Custom\Tools\ContactExport;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Acl\Permission;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use PDO;

/** Records and reads export metadata without weakening the native export ACL. */
final class ContactExportAuditService
{
    private const RETENTION_DAYS = 30;

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private Acl $acl,
        private User $user,
        private FileStorageManager $fileStorageManager,
    ) {}

    /** @return array<string, mixed> */
    public function register(string $attachmentId, string $source, int $count, string $format, string $exportName): array
    {
        $this->requireExportAccess();

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $attachmentId) || $count < 0) {
            throw new BadRequest('Invalid export metadata.');
        }

        if (!in_array($source, ['Selected contacts', 'Filtered contacts'], true)) {
            throw new BadRequest('Invalid export source.');
        }

        $normalizedFormat = strtolower(trim($format));
        $normalizedName = $this->normalizeExportName($exportName, $normalizedFormat);

        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)->getById($attachmentId);

        if (
            !$attachment ||
            $attachment->getRole() !== Attachment::ROLE_EXPORT_FILE ||
            $attachment->get('createdById') !== $this->user->getId()
        ) {
            throw new Forbidden('The export file is not available to this user.');
        }

        $attachment->set('nexaExportSource', $source);
        $attachment->set('nexaExportRecordCount', $count);
        $attachment->set('nexaExportFormat', $normalizedFormat);
        $attachment->set('nexaExportExpiresAt', $this->expiresAt());
        $attachment->setName($normalizedName);
        $this->entityManager->saveEntity($attachment);

        return ['id' => $attachment->getId(), 'registered' => true];
    }

    /** @return array{list: array<int, array<string, mixed>>, total: int} */
    public function list(): array
    {
        $this->requireExportAccess();
        $tenant = $this->tenantContextStore->require();
        $parameters = [
            'tenantId' => $tenant->tenantId,
            'serviceId' => $tenant->serviceId,
            'role' => Attachment::ROLE_EXPORT_FILE,
        ];
        $ownerClause = '';

        // Tenant administrators review the tenant audit; other users see only their own exports.
        if (!$this->user->isAdmin()) {
            $ownerClause = ' AND a.created_by_id = :userId';
            $parameters['userId'] = $this->user->getId();
        }

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT a.id, a.name, a.nexa_export_source, a.nexa_export_record_count, ' .
            'a.nexa_export_format, a.nexa_export_expires_at, a.created_at, ' .
            'u.first_name, u.last_name, u.user_name, u.login_email ' .
            'FROM attachment a LEFT JOIN user u ON u.id = a.created_by_id ' .
            'AND u.tenant_id = a.tenant_id AND u.service_id = a.service_id ' .
            'WHERE a.tenant_id = :tenantId AND a.service_id = :serviceId ' .
            'AND a.deleted = 0 AND a.role = :role AND a.nexa_export_source IS NOT NULL' .
            $ownerClause . ' ORDER BY a.created_at DESC LIMIT 200'
        );
        $statement->execute($parameters);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $list = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $expiresAt = new DateTimeImmutable((string) $row['nexa_export_expires_at'], new DateTimeZone('UTC'));
            $userName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            $list[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'source' => (string) $row['nexa_export_source'],
                'count' => (int) $row['nexa_export_record_count'],
                'format' => (string) $row['nexa_export_format'],
                'createdAt' => (string) $row['created_at'],
                'expiresAt' => (string) $row['nexa_export_expires_at'],
                'expired' => $expiresAt <= $now,
                'userName' => $userName !== '' ? $userName : (string) $row['user_name'],
                'userEmail' => (string) ($row['login_email'] ?? ''),
            ];
        }

        return ['list' => $list, 'total' => count($list)];
    }

    /** @return array{name: string, type: string, contents: string} */
    public function download(string $attachmentId): array
    {
        $this->requireExportAccess();

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $attachmentId)) {
            throw new NotFound('Export file not found.');
        }

        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)->getById($attachmentId);

        if (
            !$attachment ||
            $attachment->getRole() !== Attachment::ROLE_EXPORT_FILE ||
            !$attachment->get('nexaExportSource') ||
            (!$this->user->isAdmin() && $attachment->get('createdById') !== $this->user->getId())
        ) {
            throw new Forbidden('The export file is not available to this user.');
        }

        $expiresAt = $attachment->get('nexaExportExpiresAt');

        if (
            !is_string($expiresAt) ||
            new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))
        ) {
            throw new Forbidden('The export file has expired.');
        }

        return [
            'name' => $attachment->getName() ?: 'contacts-export',
            'type' => $attachment->getType() ?: 'application/octet-stream',
            'contents' => base64_encode($this->fileStorageManager->getContents($attachment)),
        ];
    }

    /** @return array{id: string, deleted: true} */
    public function delete(string $attachmentId): array
    {
        $this->requireExportAccess();

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $attachmentId)) {
            throw new NotFound('Export file not found.');
        }

        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE)->getById($attachmentId);

        if (
            !$attachment ||
            $attachment->getRole() !== Attachment::ROLE_EXPORT_FILE ||
            !$attachment->get('nexaExportSource') ||
            (!$this->user->isAdmin() && $attachment->get('createdById') !== $this->user->getId())
        ) {
            throw new Forbidden('The export file is not available to this user.');
        }

        if ($this->fileStorageManager->exists($attachment)) {
            $this->fileStorageManager->unlink($attachment);
        }

        $this->entityManager->removeEntity($attachment);

        return ['id' => $attachmentId, 'deleted' => true];
    }

    private function requireExportAccess(): void
    {
        $this->tenantContextStore->require();

        if (
            $this->acl->getPermissionLevel(Permission::EXPORT) !== Table::LEVEL_YES ||
            !$this->acl->check('Contact', Table::ACTION_READ)
        ) {
            throw new Forbidden('You do not have permission to export contacts.');
        }
    }

    private function expiresAt(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::RETENTION_DAYS . ' days')
            ->format('Y-m-d H:i:s');
    }

    private function normalizeExportName(string $name, string $format): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9 _()-]*$/', $name)) {
            throw new BadRequest('Invalid export name.');
        }

        $extension = in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv';
        $name = preg_replace('/\.(csv|xlsx)$/i', '', $name) ?: $name;

        return $name . '.' . $extension;
    }
}
