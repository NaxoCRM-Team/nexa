<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Core\FileStorage\Manager;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\Attachment;
use Espo\Entities\EmailTemplate;
use Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryAttachment;

final class MarketingAttachmentService
{
    public function __construct(private EntityManager $entityManager, private TenantContextStore $store, private Manager $storage, private Acl $acl) {}

    /** @return list<DeliveryAttachment> */
    public function files(array $ids, bool $checkAcl = true): array
    {
        $context = $this->store->require();
        if (!array_is_list($ids) || count($ids) > 10 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) throw new BadRequest('Choose at most 10 distinct marketing attachments.');
        if ($checkAcl && $ids && !$this->acl->checkField('EmailTemplate', 'attachments')) throw new Forbidden('Template attachments are not available.');
        $result = []; $total = 0;
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{1,64}$/', $id)) throw new BadRequest('Invalid marketing attachment.');
            $attachment = $this->entityManager->getEntityById('Attachment', $id);
            if (!$attachment instanceof Attachment || $attachment->get('deleted') || $attachment->isBeingUploaded() || $attachment->get('tenantId') !== $context->tenantId || $attachment->get('serviceId') !== $context->serviceId || ($checkAcl && !$this->acl->checkEntity($attachment, 'read'))) throw new BadRequest('A selected attachment is unavailable or not permitted.');
            if ($this->storage->getSize($attachment) > DeliveryAttachment::MAX_BYTES) throw new BadRequest('Each marketing attachment must be no larger than 5 MB.');
            $stream = $this->storage->getStream($attachment); $contents = '';
            try {
                while (!$stream->eof()) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '' && !$stream->eof()) throw new BadRequest('The attachment could not be read.');
                    $contents .= $chunk;
                    if (strlen($contents) > DeliveryAttachment::MAX_BYTES) throw new BadRequest('Each marketing attachment must be no larger than 5 MB.');
                }
            } finally { $stream->close(); }
            try { $result[] = new DeliveryAttachment((string) $attachment->getName(), (string) $attachment->getType(), $contents); }
            catch (\InvalidArgumentException $error) { throw new BadRequest($error->getMessage()); }
            $total += strlen($contents);
            if ($total > 10 * 1024 * 1024) throw new BadRequest('Marketing attachments cannot exceed 10 MB in total.');
        }
        return $result;
    }

    public function manifest(array $ids, bool $checkAcl = true): array
    {
        return $this->manifestFromFiles($ids, $this->files($ids, $checkAcl));
    }

    private function manifestFromFiles(array $ids, array $files): array
    {
        $manifest = [];
        foreach ($files as $index => $file) $manifest[] = ['id' => $ids[$index], 'name' => $file->name, 'mimeType' => $file->mimeType, 'size' => strlen($file->contents), 'sha256' => hash('sha256', $file->contents)];
        usort($manifest, static fn ($a, $b) => strcmp($a['id'], $b['id']));
        return $manifest;
    }

    public function templateIds(EmailTemplate $template): array
    {
        $ids = [];
        foreach ($template->getAttachments() as $attachment) $ids[] = $attachment->getId();
        return $ids;
    }

    public function copyForTemplate(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $source = $this->entityManager->getEntityById('Attachment', $id);
            if (!$source instanceof Attachment || !$this->acl->checkEntity($source, 'read')) throw new BadRequest('A selected attachment is unavailable or not permitted.');
            $copy = $this->entityManager->getRepository('Attachment')->getCopiedAttachment($source);
            $copy->set('field', 'attachments');
            $this->entityManager->saveEntity($copy);
            $result[] = $copy->getId();
        }
        return $result;
    }

    public function versionManifest(string $profileId, int $version, string $eventType = 'saved'): array
    {
        $context = $this->store->require();
        $types = $eventType === 'approved' ? "event_type='approved'" : "event_type IN ('created','updated')";
        $query = $this->entityManager->getPDO()->prepare("SELECT payload_json FROM nexa_marketing_email_event WHERE profile_id=? AND version_number=? AND tenant_id=? AND service_id=? AND {$types} ORDER BY occurred_at DESC,id DESC LIMIT 1");
        $query->execute([$profileId, $version, $context->tenantId, $context->serviceId]);
        $payload = $query->fetchColumn();
        return $payload ? (json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['attachments'] ?? []) : [];
    }

    /** @return list<DeliveryAttachment> */
    public function approvedFiles(array $profile, EmailTemplate $template, bool $checkAcl = false): array
    {
        $ids = $this->templateIds($template);
        $expected = $this->versionManifest($profile['id'], (int) $profile['version_number'], 'approved');
        $files = $this->files($ids, $checkAcl);
        if ($this->manifestFromFiles($ids, $files) !== $expected) throw new BadRequest('Attachments changed after approval. Return the email to draft and approve its files again.');
        return $files;
    }
}
