<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\FileStorage\Manager;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;
use Espo\Entities\{Attachment, EmailTemplate, User};
use PDO;
use stdClass;

/** Explicitly published immutable email copies; source assets and private storage remain unchanged. */
final class MarketingImageService
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private EntityManager $em,
        private TenantContextStore $store,
        private Manager $storage,
        private Acl $acl,
        private User $user,
        private Config $config,
        private PublicRequestLimiter $limiter,
    ) {}

    public function catalog(): array
    {
        $this->requireAdmin(); $context = $this->store->require();
        $query = $this->em->getPDO()->prepare(
            "SELECT * FROM nexa_marketing_image_publication WHERE tenant_id=? AND service_id=? AND status='published' ORDER BY created_at DESC,id DESC LIMIT 100"
        );
        $query->execute([$context->tenantId, $context->serviceId]);
        $images = array_map(fn (array $row) => $this->metadata($row), $query->fetchAll(PDO::FETCH_ASSOC));
        $query = $this->em->getPDO()->prepare(
            'SELECT p.id,p.display_name,p.alt_text,p.current_attachment_id,p.document_id FROM nexa_asset_profile p ' .
            'INNER JOIN attachment a ON a.id=p.current_attachment_id AND a.tenant_id=p.tenant_id AND a.service_id=p.service_id ' .
            "WHERE p.tenant_id=? AND p.service_id=? AND p.status='active' AND p.access_scope='public' AND a.deleted=0 " .
            "AND a.type IN ('image/png','image/jpeg','image/gif','image/webp') AND a.size<=? ORDER BY p.modified_at DESC,p.id DESC LIMIT 100"
        );
        $query->execute([$context->tenantId, $context->serviceId, self::MAX_BYTES]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {$this->source($context, $row['id']);} catch (BadRequest|Forbidden|NotFound) {continue;}
            $images[] = ['id' => 'asset-' . $row['id'], 'assetId' => $row['id'], 'name' => $row['display_name'],
                'alt' => $row['alt_text'], 'url' => null, 'requiresPublication' => true];
        }
        return $images;
    }

    public function publish(stdClass $input): array
    {
        $this->requireAdmin(true);
        if (($input->confirmPublicCopy ?? null) !== true) throw new BadRequest('Confirm that this email image copy may be publicly viewed.');
        $assetId = $input->assetId ?? null;
        if (!is_string($assetId) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $assetId)) throw new BadRequest('Choose a public image asset.');
        $context = $this->store->require(); $pdo = $this->em->getPDO(); $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        $snapshot = null;
        try {
            [$asset, $source] = $this->source($context, $assetId, true);
            $bytes = $this->bytes($source); $hash = hash('sha256', $bytes);
            $existing = $pdo->prepare("SELECT * FROM nexa_marketing_image_publication WHERE asset_id=? AND tenant_id=? AND service_id=? AND checksum_sha256=? AND status='published' ORDER BY created_at DESC,id DESC LIMIT 1");
            $existing->execute([$assetId, $context->tenantId, $context->serviceId, $hash]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $id = $this->uuid();
                $snapshot = $this->em->getRDBRepositoryByClass(Attachment::class)->getNew();
                $snapshot->setName('email-image.' . ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'][$source->getType()])
                    ->setType($source->getType())->setRole(Attachment::ROLE_ATTACHMENT)
                    ->setTargetField('nexaMarketingImage')->setSize(strlen($bytes))->setContents($bytes)
                    ->set('global', true);
                $this->em->saveEntity($snapshot, [SaveOption::SILENT => true]);
                $token = bin2hex(random_bytes(24));
                $insert = $pdo->prepare('INSERT INTO nexa_marketing_image_publication (id,tenant_id,service_id,asset_id,attachment_id,public_token,display_name,alt_text,mime_type,file_size,checksum_sha256,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $insert->execute([$id,$context->tenantId,$context->serviceId,$assetId,$snapshot->getId(),$token,$asset['display_name'],$asset['alt_text'],$source->getType(),strlen($bytes),$hash,$this->user->getId()]);
                $row = $this->row($context, $id);
            }
            $result = $this->withPreview($row);
            if ($owns) $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            if ($snapshot) {try {$this->storage->unlink($snapshot);} catch (\Throwable) {}}
            throw $error;
        }
    }

    public function preview(string $id): array
    {
        $this->requireAdmin();
        return $this->withPreview($this->row($this->store->require(), $this->publicationId($id)));
    }

    public function revoke(string $id): array
    {
        $this->requireAdmin(true); $context = $this->store->require(); $id = $this->publicationId($id);
        $exists = $this->em->getPDO()->prepare('SELECT id FROM nexa_marketing_image_publication WHERE id=? AND tenant_id=? AND service_id=?');
        $exists->execute([$id,$context->tenantId,$context->serviceId]);
        if (!$exists->fetchColumn()) throw new NotFound('The email image is unavailable.');
        $query = $this->em->getPDO()->prepare("UPDATE nexa_marketing_image_publication SET status='revoked',revoked_by_id=?,revoked_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=? AND status='published'");
        $query->execute([$this->user->getId(),$id,$context->tenantId,$context->serviceId]);
        return ['id' => 'mail-' . $id, 'revoked' => true];
    }

    /** Public callers can obtain only a verified snapshot in the token-resolved tenant/service. */
    public function publicImage(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new NotFound('Image not found.');
        $context = $this->store->require();
        $query = $this->em->getPDO()->prepare("SELECT * FROM nexa_marketing_image_publication WHERE public_token=? AND tenant_id=? AND service_id=? AND status='published' LIMIT 1");
        $query->execute([$token,$context->tenantId,$context->serviceId]); $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Image not found.');
        $this->limiter->enforce($context->tenantId, $context->serviceId, 'marketing-images', 600, 60);
        return ['mimeType' => $row['mime_type'], 'bytes' => $this->snapshotBytes($row), 'checksum' => $row['checksum_sha256']];
    }

    /** Managed image URLs are always checked centrally, including hand-edited HTML. */
    public function manifest(string $html): array
    {
        if (strlen($html) > 2_000_000) throw new BadRequest('Email content is too large.');
        $context = $this->store->require(); $result = [];
        $document = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        try {$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);}
        finally {libxml_clear_errors(); libxml_use_internal_errors($previous);}
        foreach ($document->getElementsByTagName('img') as $image) {
            $url = $image->getAttribute('src'); $parsed = parse_url($url);
            if (!is_array($parsed)) continue;
            parse_str($parsed['query'] ?? '', $query);
            if (($query['entryPoint'] ?? null) !== 'NexaMarketingImage') continue;
            $token = $query['token'] ?? null;
            if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token)) throw new BadRequest('An email image URL is invalid.');
            $select = $this->em->getPDO()->prepare("SELECT * FROM nexa_marketing_image_publication WHERE public_token=? AND tenant_id=? AND service_id=? AND status='published' LIMIT 1");
            $select->execute([$token,$context->tenantId,$context->serviceId]); $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row || $url !== $this->metadata($row)['url']) throw new BadRequest('An email image is unavailable in this workspace.');
            if (isset($result[$row['id']])) continue;
            $this->snapshotBytes($row);
            $result[$row['id']] = ['id' => $row['id'], 'sha256' => $row['checksum_sha256'], 'size' => (int) $row['file_size']];
            if (count($result) > 20) throw new BadRequest('Choose at most 20 distinct hosted email images.');
        }
        ksort($result); return array_values($result);
    }

    public function versionManifest(string $profileId, int $version, bool $approved = false): array
    {
        $context = $this->store->require();
        $types = $approved ? "event_type='approved'" : "event_type IN ('created','updated')";
        $query = $this->em->getPDO()->prepare("SELECT payload_json FROM nexa_marketing_email_event WHERE profile_id=? AND version_number=? AND tenant_id=? AND service_id=? AND {$types} ORDER BY occurred_at DESC,id DESC LIMIT 1");
        $query->execute([$profileId,$version,$context->tenantId,$context->serviceId]); $payload = $query->fetchColumn();
        return $payload ? (json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['images'] ?? []) : [];
    }

    public function requireApproved(array $profile, EmailTemplate $template): void
    {
        if ($this->manifest((string) $template->get('body')) !== $this->versionManifest($profile['id'], (int) $profile['version_number'], true)) {
            throw new BadRequest('Hosted images changed after approval. Save and approve the email again.');
        }
    }

    private function source(TenantContext $context, string $id, bool $lock = false): array
    {
        $query = $this->em->getPDO()->prepare("SELECT * FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=? AND status='active' AND access_scope='public' LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id,$context->tenantId,$context->serviceId]); $asset = $query->fetch(PDO::FETCH_ASSOC);
        if (!$asset || !$asset['document_id']) throw new NotFound('Choose an active public image asset.');
        $file = $this->em->getEntityById('Attachment', $asset['current_attachment_id']);
        $document = $this->em->getEntityById('Document', $asset['document_id']);
        if (!$file instanceof Attachment || !$document || $document->get('deleted') || $file->get('deleted') || $file->isBeingUploaded() ||
            $file->get('tenantId') !== $context->tenantId || $file->get('serviceId') !== $context->serviceId ||
            $document->get('tenantId') !== $context->tenantId || $document->get('serviceId') !== $context->serviceId ||
            !in_array($file->getType(), self::TYPES, true) || !$this->acl->checkEntity($document, 'read') || !$this->acl->checkEntity($file, 'read')) {
            throw new NotFound('The public image is unavailable.');
        }
        return [$asset, $file];
    }

    private function bytes(Attachment $file): string
    {
        if ($this->storage->getSize($file) > self::MAX_BYTES) throw new BadRequest('Email images cannot exceed 8 MB.');
        $stream = $this->storage->getStream($file); $bytes = '';
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '' && !$stream->eof()) throw new BadRequest('The image could not be read.');
                $bytes .= $chunk;
                if (strlen($bytes) > self::MAX_BYTES) throw new BadRequest('Email images cannot exceed 8 MB.');
            }
        } finally {$stream->close();}
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes); $dimensions = @getimagesizefromstring($bytes);
        if ($type !== $file->getType() || !in_array($type, self::TYPES, true) || !$dimensions ||
            $dimensions[0] > 8192 || $dimensions[1] > 8192 || $dimensions[0] * $dimensions[1] > 20_000_000) {
            throw new BadRequest('Choose a valid PNG, JPG, GIF or WebP image up to 20 megapixels.');
        }
        return $bytes;
    }

    private function snapshotBytes(array $row): string
    {
        $file = $this->em->getEntityById('Attachment', $row['attachment_id']);
        if (!$file instanceof Attachment || $file->get('deleted') || $file->isBeingUploaded() ||
            $file->get('tenantId') !== $row['tenant_id'] || $file->get('serviceId') !== $row['service_id'] ||
            $file->getType() !== $row['mime_type']) throw new NotFound('Image not found.');
        $bytes = $this->bytes($file);
        if (strlen($bytes) !== (int) $row['file_size'] || !hash_equals($row['checksum_sha256'], hash('sha256', $bytes))) throw new BadRequest('The hosted image failed its integrity check.');
        return $bytes;
    }

    private function row(TenantContext $context, string $id): array
    {
        $query = $this->em->getPDO()->prepare("SELECT * FROM nexa_marketing_image_publication WHERE id=? AND tenant_id=? AND service_id=? AND status='published' LIMIT 1");
        $query->execute([$id,$context->tenantId,$context->serviceId]); $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('The email image is unavailable.'); return $row;
    }

    private function metadata(array $row): array
    {
        return ['id'=>'mail-' . $row['id'], 'assetId'=>$row['asset_id'], 'name'=>$row['display_name'], 'alt'=>$row['alt_text'],
            'url'=>rtrim((string) $this->config->get('siteUrl'), '/') . '/?entryPoint=NexaMarketingImage&token=' . $row['public_token'], 'requiresPublication'=>false];
    }

    private function withPreview(array $row): array
    {
        return $this->metadata($row) + ['preview'=>'data:' . $row['mime_type'] . ';base64,' . base64_encode($this->snapshotBytes($row))];
    }

    private function requireAdmin(bool $write = false): void
    {
        $action = $write ? 'edit' : 'read';
        if (!$this->user->isAdmin() || !$this->acl->checkScope('MassEmail', $action) || !$this->acl->checkScope('EmailTemplate', $action) ||
            !$this->acl->checkScope('Document', 'read') || !$this->acl->checkScope('Attachment', 'read')) throw new Forbidden('Marketing image administration is not allowed.');
    }

    private function publicationId(string $id): string
    {
        if (!preg_match('/^mail-([a-f0-9-]{36})$/', $id, $match)) throw new BadRequest('Choose a hosted email image.'); return $match[1];
    }

    private function uuid(): string
    {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6]) & 15) | 64); $bytes[8]=chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
