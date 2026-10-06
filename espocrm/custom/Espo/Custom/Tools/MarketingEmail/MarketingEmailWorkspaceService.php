<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Governs marketing content while retaining native EmailTemplate and MassEmail records. */
final class MarketingEmailWorkspaceService
{
    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServices,
        private TenantContextStore $tenantContextStore,
        private MarketingDeliveryService $delivery,
        private Acl $acl,
        private User $user,
        private MarketingAttachmentService $attachments,
        private \Espo\Custom\Tools\TenantFile\TenantImageLibrary $fileLibrary,
        private \Espo\Core\Utils\Config $config,
        private \Espo\Custom\Tools\Asset\AssetWorkspaceService $assetLibrary,
        private MarketingImageService $images,
    ) {}

    public function designerImages(?string $id = null): array
    {
        if ($id !== null && str_starts_with($id, 'mail-')) return $this->images->preview($id);
        if ($id !== null) return $this->legacyDesignerImages($id);
        $legacy = $this->legacyDesignerImages();
        return ['images' => [...$this->images->catalog(), ...array_map(static fn (array $image) => $image + ['legacy' => true], $legacy['images'])]];
    }

    /** Preserve saved landing-page image URLs without creating new public resources. */
    private function legacyDesignerImages(?string $id = null): array
    {
        $this->requireAccess(Table::ACTION_READ);
        if (!$this->user->isAdmin() || !$this->acl->checkScope('Document', Table::ACTION_READ) ||
            !$this->acl->checkScope('Attachment', Table::ACTION_READ)) {
            throw new Forbidden('Marketing image access is not allowed.');
        }
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT p.public_key,p.slug,v.configuration_json FROM nexa_landing_page p " .
            'INNER JOIN nexa_landing_page_version v ON v.landing_page_id=p.id AND v.tenant_id=p.tenant_id ' .
            'AND v.service_id=p.service_id AND v.version_number=p.version_number ' .
            "WHERE p.tenant_id=? AND p.service_id=? AND p.status='published' ORDER BY p.published_at DESC LIMIT 100"
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        $assets = [];
        $assetQuery = $this->entityManager->getPDO()->prepare(
            "SELECT current_attachment_id,document_id,display_name,alt_text FROM nexa_asset_profile " .
            "WHERE id=? AND tenant_id=? AND service_id=? AND status='active' AND access_scope='public' LIMIT 1"
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $page) {
            $configuration = json_decode((string) $page['configuration_json'], true) ?: [];
            foreach (($configuration['blocks'] ?? []) as $block) {
                $assetId = (string) ($block['assetId'] ?? '');
                if ($assetId === '' || isset($assets[$assetId]) || ($id !== null && $assetId !== $id)) continue;
                $assetQuery->execute([$assetId, $context->tenantId, $context->serviceId]);
                $asset = $assetQuery->fetch(PDO::FETCH_ASSOC);
                if (!$asset || !$asset['document_id']) continue;
                $document = $this->entityManager->getEntityById('Document', (string) $asset['document_id']);
                $file = $this->entityManager->getEntityById('Attachment', (string) $asset['current_attachment_id']);
                if (!$document || !$file instanceof \Espo\Entities\Attachment ||
                    $document->get('deleted') || $document->get('tenantId') !== $context->tenantId || $document->get('serviceId') !== $context->serviceId ||
                    !$this->acl->checkEntity($document) || !$this->acl->checkEntity($file) ||
                    $file->get('deleted') || $file->isBeingUploaded() ||
                    $file->get('tenantId') !== $context->tenantId || $file->get('serviceId') !== $context->serviceId ||
                    !in_array($file->get('type'), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ||
                    (int) $file->get('size') > 8 * 1024 * 1024) continue;
                $url = rtrim((string) $this->config->get('siteUrl'), '/') . '/?' . http_build_query([
                    'entryPoint' => 'NexaLandingAsset', 'key' => $page['public_key'], 'slug' => $page['slug'], 'asset' => $assetId,
                ], '', '&', PHP_QUERY_RFC3986);
                $assets[$assetId] = ['id' => $assetId, 'name' => $asset['display_name'], 'alt' => $asset['alt_text'], 'url' => $url];
            }
        }
        if ($id !== null) {
            if (!isset($assets[$id])) throw new NotFound('The published image is unavailable.');
            $preview = $this->assetLibrary->download($id);
            $bytes = base64_decode($preview['data'], true);
            if ($bytes === false || strlen($bytes) > 8 * 1024 * 1024 ||
                !in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                throw new BadRequest('Choose a valid image no larger than 8 MB.');
            }
            return $assets[$id] + ['preview' => 'data:' . $preview['mimeType'] . ';base64,' . $preview['data']];
        }
        return ['images' => array_values($assets)];
    }

    /** @return array<string, mixed> */
    public function getWorkspace(): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $this->ensureProfiles($context);

        $delivery = $this->delivery->readiness();
        return [
            'emails' => $this->emails($context),
            'campaigns' => $this->campaigns($context),
            'purposes' => $this->purposes($context),
            'summary' => $this->summary($context),
            'permissions' => [
                'create' => $this->canWrite(Table::ACTION_CREATE),
                'edit' => $this->canWrite(Table::ACTION_EDIT),
                'approve' => $this->user->isAdmin() && $this->acl->checkScope('MassEmail', Table::ACTION_EDIT),
                'attachments' => $this->acl->checkField('EmailTemplate', 'attachments') && $this->acl->checkField('EmailTemplate', 'attachments', Table::ACTION_EDIT),
            ],
            'delivery' => $delivery,
            'usage' => $this->user->isAdmin() ? $this->delivery->usage() : null,
            'deliveryEnabled' => $delivery['ready'],
            'deliveryMessage' => $delivery['ready'] ? 'Delivery prerequisites are ready.' : 'Scheduling unlocks after a verified sending domain and marketing delivery provider are configured.',
        ];
    }

    /** Upload into the existing tenant library; selecting does not approve delivery. */
    public function uploadAttachment(stdClass $input): array
    {
        $this->tenantContextStore->require();
        $this->requireAccess(Table::ACTION_READ);
        if (!$this->canWrite(Table::ACTION_CREATE) && !$this->canWrite(Table::ACTION_EDIT)) throw new Forbidden('Marketing email editing is not allowed.');
        if (!$this->acl->checkField('EmailTemplate', 'attachments') || !$this->acl->checkField('EmailTemplate', 'attachments', Table::ACTION_EDIT)) throw new Forbidden('Template attachment editing is not allowed.');
        $encoded = $input->data ?? null;
        $name = $input->name ?? null;
        $type = $input->mimeType ?? null;
        if (!is_string($encoded) || !is_string($name) || !is_string($type) || strlen($encoded) > 4 * (int) ceil(\Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryAttachment::MAX_BYTES / 3)) throw new BadRequest('Choose a supported marketing attachment no larger than 5 MB.');
        $contents = base64_decode($encoded, true);
        if ($contents === false) throw new BadRequest('The attachment payload is invalid.');
        try { new \Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryAttachment($name, $type, $contents); }
        catch (\InvalidArgumentException $error) { throw new BadRequest($error->getMessage()); }
        return $this->fileLibrary->uploadFile($name, $type, $encoded);
    }

    /** Read a reusable native template without copying files or creating a draft. */
    public function reusableTemplate(string $id): array
    {
        $this->requireAccess(Table::ACTION_READ);
        if (!$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can configure marketing email.');
        $context = $this->tenantContextStore->require();
        $template = $this->recordServices->get('EmailTemplate')->getEntity($id);
        if (!$template instanceof \Espo\Entities\EmailTemplate || $template->get('tenantId') !== $context->tenantId || $template->get('serviceId') !== $context->serviceId) throw new NotFound('Email template not found.');
        foreach (['name', 'subject', 'body', 'isHtml'] as $field) {
            if (!$this->acl->checkField('EmailTemplate', $field)) throw new Forbidden('Template content is not available.');
        }
        $ids = $this->attachments->templateIds($template);
        $this->images->manifest((string) $template->get('body'));
        if ($ids && !$this->acl->checkField('EmailTemplate', 'attachments', Table::ACTION_EDIT)) throw new Forbidden('Template attachment editing is not allowed.');
        $files = array_map(static function ($file) { unset($file['sha256']); return $file; }, $this->attachments->manifest($ids));
        return ['id' => $id, 'name' => $template->get('name'), 'subject' => $template->get('subject'), 'body' => $template->get('body'), 'isHtml' => $template->get('isHtml'), 'attachments' => $files];
    }

    /** @return array<string, mixed> */
    public function save(stdClass $input, ?string $id = null): array
    {
        $context = $this->tenantContextStore->require();
        $isNew = $id === null;
        $this->requireAccess($isNew ? Table::ACTION_CREATE : Table::ACTION_EDIT);
        $data = $this->input($context, $input);
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();

        try {
            $attachmentIds = $input->attachmentsIds ?? null;
            if ($attachmentIds === null && !$isNew) {
                $existing = $this->requireProfile($context, $id, true);
                $existingTemplate = $this->entityManager->getEntityById('EmailTemplate', $existing['email_template_id']);
                if (!$existingTemplate instanceof \Espo\Entities\EmailTemplate) throw new NotFound('Email template not found.');
                $attachmentIds = $this->attachments->templateIds($existingTemplate);
            }
            if ($attachmentIds === null) $attachmentIds = [];
            if (!is_array($attachmentIds)) throw new BadRequest('Invalid attachment selection.');
            if (isset($input->attachmentsIds) && !$this->acl->checkField('EmailTemplate', 'attachments', Table::ACTION_EDIT)) throw new Forbidden('Template attachment editing is not allowed.');
            $manifest = $this->attachments->manifest($attachmentIds);
            $imageManifest = $this->images->manifest($data['bodyHtml']);
            $attachmentIds = $this->attachments->copyForTemplate($attachmentIds);
            if ($isNew) {
                $template = $this->recordServices->get('EmailTemplate')->create((object) [
                    'name' => $data['name'], 'subject' => $data['subject'], 'body' => $data['bodyHtml'],
                    'isHtml' => true, 'oneOff' => false, 'attachmentsIds' => $attachmentIds,
                ], CreateParams::create());
                $massEmail = $this->recordServices->get('MassEmail')->create((object) [
                    'name' => $data['name'], 'status' => 'Draft', 'startAt' => $data['startAt'],
                    'emailTemplateId' => $template->getId(), 'campaignId' => $data['campaignId'],
                    'targetListsIds' => $data['audienceIds'], 'excludingTargetListsIds' => $data['exclusionIds'],
                    'fromAddress' => $data['fromAddress'], 'fromName' => $data['fromName'],
                    'replyToAddress' => $data['replyToAddress'], 'replyToName' => $data['replyToName'],
                ], CreateParams::create());
                $id = $this->uuid();
                $nativeId = $massEmail->getId();
                $templateId = $template->getId();
                $version = 1;
                $insert = $pdo->prepare('INSERT INTO nexa_marketing_email_profile (id,tenant_id,service_id,mass_email_id,delivery_mode,lifecycle_status,purpose_id,preheader,version_number,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $insert->execute([$id, $context->tenantId, $context->serviceId, $nativeId, $data['deliveryMode'], 'draft', $data['purposeId'], $data['preheader'], $version, $this->user->getId(), $this->user->getId()]);
            } else {
                $profile = $this->requireProfile($context, $id, true);
                if (!in_array($profile['lifecycle_status'], ['draft', 'review', 'approved'], true)) throw new BadRequest('This email can no longer be edited.');
                $nativeId = (string) $profile['mass_email_id'];
                $templateId = (string) $profile['email_template_id'];
                $version = (int) $profile['version_number'] + 1;
                $this->recordServices->get('EmailTemplate')->update($templateId, (object) ['name' => $data['name'], 'subject' => $data['subject'], 'body' => $data['bodyHtml'], 'attachmentsIds' => $attachmentIds], UpdateParams::create());
                $this->recordServices->get('MassEmail')->update($nativeId, (object) [
                    'name' => $data['name'], 'status' => 'Draft', 'startAt' => $data['startAt'],
                    'campaignId' => $data['campaignId'], 'targetListsIds' => $data['audienceIds'],
                    'excludingTargetListsIds' => $data['exclusionIds'], 'fromAddress' => $data['fromAddress'],
                    'fromName' => $data['fromName'], 'replyToAddress' => $data['replyToAddress'],
                    'replyToName' => $data['replyToName'],
                ], UpdateParams::create());
                $update = $pdo->prepare("UPDATE nexa_marketing_email_profile SET delivery_mode=?,lifecycle_status='draft',purpose_id=?,preheader=?,version_number=?,submitted_by_id=NULL,submitted_at=NULL,approved_by_id=NULL,approved_at=NULL,modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?");
                $update->execute([$data['deliveryMode'], $data['purposeId'], $data['preheader'], $version, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            }

            $this->insertVersion($context, (string) $id, $version, $templateId, $data);
            $manifest = $this->attachments->manifest($this->attachments->templateIds($this->entityManager->getEntityById('EmailTemplate', $templateId)));
            $this->insertEvent($context, (string) $id, $isNew ? 'created' : 'updated', $version, ['nativeId' => $nativeId, 'attachments' => $manifest, 'images' => $imageManifest]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['id' => $id, 'massEmailId' => $nativeId, 'version' => $version, 'created' => $isNew];
    }

    /** @return array<string, mixed> */
    public function transition(string $id, stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $profile = $this->requireProfile($context, $id, true);
            $action = strtolower(trim((string) ($input->action ?? '')));
            $current = (string) $profile['lifecycle_status'];
            $next = match ($action) {
                'submit' => $current === 'draft' ? 'review' : null,
                'approve' => $current === 'review' ? 'approved' : null,
                'return' => in_array($current, ['review', 'approved'], true) ? 'draft' : null,
                default => null,
            };
            if ($next === null) throw new BadRequest('This email cannot make the requested status change.');
            if ($action === 'approve' && !$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can approve marketing email.');
            $manifest = [];
            $imageManifest = [];
            if ($action === 'approve') {
                $template = $this->entityManager->getEntityById('EmailTemplate', $profile['email_template_id']);
                if (!$template instanceof \Espo\Entities\EmailTemplate) throw new NotFound('Email template not found.');
                $manifest = $this->attachments->manifest($this->attachments->templateIds($template));
                if ($manifest !== $this->attachments->versionManifest($id, (int) $profile['version_number'])) throw new BadRequest('Attachments changed since the draft was saved. Save the draft again before approval.');
                $imageManifest = $this->images->manifest((string) $template->get('body'));
                if ($imageManifest !== $this->images->versionManifest($id, (int) $profile['version_number'])) throw new BadRequest('Hosted images changed since the draft was saved. Save the draft again before approval.');
            }

            $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status=?,submitted_by_id=IF(?='review',?,submitted_by_id),submitted_at=IF(?='review',NOW(6),submitted_at),approved_by_id=IF(?='approved',?,NULL),approved_at=IF(?='approved',NOW(6),NULL),modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?");
            $statement->execute([$next, $next, $this->user->getId(), $next, $next, $this->user->getId(), $next, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            $this->insertEvent($context, $id, $next, (int) $profile['version_number'], ['previousStatus' => $current, 'attachments' => $manifest, 'images' => $imageManifest]);
            if ($ownsTransaction) $pdo->commit();
            return ['id' => $id, 'status' => $next];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function input(TenantContext $context, stdClass $input): array
    {
        $name = trim((string) ($input->name ?? ''));
        $subject = trim((string) ($input->subject ?? ''));
        $bodyHtml = trim((string) ($input->bodyHtml ?? ''));
        if ($name === '') throw new BadRequest('Email name is required.');
        if ($subject === '') throw new BadRequest('Subject is required.');
        if ($bodyHtml === '') throw new BadRequest('Email content is required.');
        if (strlen($bodyHtml) > 2_000_000) throw new BadRequest('Email content is too large.');
        $campaignId = trim((string) ($input->campaignId ?? ''));
        $campaign = $this->campaign($context, $campaignId);
        $purposeId = trim((string) ($input->purposeId ?? $campaign['purpose_id'] ?? ''));
        if ($purposeId === '' || $purposeId !== (string) $campaign['purpose_id']) throw new BadRequest('Use the email consent purpose configured for the selected campaign.');
        return [
            'name' => mb_substr($name, 0, 255), 'subject' => mb_substr($subject, 0, 255),
            'preheader' => ($value = trim((string) ($input->preheader ?? ''))) !== '' ? mb_substr($value, 0, 255) : null,
            'bodyHtml' => $bodyHtml, 'bodyText' => trim((string) ($input->bodyText ?? '')) ?: null,
            'campaignId' => $campaignId, 'purposeId' => $purposeId,
            'audienceIds' => $this->decodeIds($campaign['audience_ids_json']),
            'exclusionIds' => $this->decodeIds($campaign['exclusion_ids_json']),
            'deliveryMode' => in_array(($input->deliveryMode ?? ''), ['campaign', 'transactional', 'single_send'], true) ? $input->deliveryMode : 'campaign',
            'startAt' => $this->dateTime($input->startAt ?? null),
            'fromAddress' => $this->email($input->fromAddress ?? null, true),
            'fromName' => $this->text($input->fromName ?? null, 255),
            'replyToAddress' => $this->email($input->replyToAddress ?? null, false),
            'replyToName' => $this->text($input->replyToName ?? null, 255),
        ];
    }

    /** @return array<string, mixed> */
    private function campaign(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT c.id,c.name,p.purpose_id,p.audience_ids_json,p.exclusion_ids_json,p.workflow_status FROM campaign c INNER JOIN nexa_campaign_profile p ON p.campaign_id=c.id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id WHERE c.id=? AND c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND p.channel='email' AND p.workflow_status<>'archived' LIMIT 1");
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BadRequest('Choose an available email campaign.');
        if (!$this->decodeIds($row['audience_ids_json'])) throw new BadRequest('The selected campaign does not have an audience.');
        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function emails(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.id,p.mass_email_id AS massEmailId,p.delivery_mode AS deliveryMode,p.lifecycle_status AS lifecycleStatus,p.purpose_id AS purposeId,p.preheader,p.version_number AS version,p.submitted_at AS submittedAt,p.approved_at AS approvedAt,m.name,m.status AS nativeStatus,m.start_at AS startAt,m.from_address AS fromAddress,m.from_name AS fromName,m.reply_to_address AS replyToAddress,m.reply_to_name AS replyToName,m.campaign_id AS campaignId,m.email_template_id AS emailTemplateId,m.modified_at AS modifiedAt,t.subject,t.body AS bodyHtml,c.name AS campaignName,cp.name AS purposeName,(SELECT COUNT(*) FROM email_queue_item q WHERE q.mass_email_id=m.id AND q.tenant_id=m.tenant_id AND q.service_id=m.service_id AND q.is_test=0 AND q.deleted=0) AS queuedCount FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id INNER JOIN email_template t ON t.id=m.email_template_id AND t.tenant_id=m.tenant_id AND t.service_id=m.service_id LEFT JOIN campaign c ON c.id=m.campaign_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id LEFT JOIN nexa_consent_purpose cp ON cp.id=p.purpose_id AND cp.tenant_id=p.tenant_id AND cp.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND m.deleted=0 AND p.lifecycle_status<>'archived' ORDER BY p.modified_at DESC");
        $statement->execute([$context->tenantId, $context->serviceId]);
        $metrics = $this->deliveryMetrics($context);
        return array_map(function (array $row) use ($metrics): array {
            $row['version'] = (int) $row['version']; $row['queuedCount'] = (int) $row['queuedCount'];
            $row['deliveryMetrics'] = $metrics[$row['id']] ?? array_fill_keys(['submitted','delivered','deferred','bounced','complained','failed','opened','clicked','unsubscribed'], 0);
            $row['attachments'] = $this->acl->checkField('EmailTemplate', 'attachments') ? array_map(static function ($file) { unset($file['sha256']); return $file; }, $this->attachments->versionManifest($row['id'], $row['version'])) : [];
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, array<string, int>> */
    private function deliveryMetrics(TenantContext $context): array
    {
        $query = $this->entityManager->getPDO()->prepare(
            "SELECT d.profile_id,SUM(d.submitted_at IS NOT NULL) AS submitted,SUM(d.status='delivered') AS delivered," .
            "SUM(d.status='deferred') AS deferred,SUM(d.status='bounced') AS bounced,SUM(d.status='complained') AS complained," .
            "SUM(d.status='failed') AS failed FROM nexa_marketing_delivery_message d INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id AND q.service_id=d.service_id WHERE d.tenant_id=? AND d.service_id=? AND q.is_test=0 GROUP BY d.profile_id"
        );
        $query->execute([$context->tenantId,$context->serviceId]);
        $result = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = $row['profile_id']; unset($row['profile_id']);
            $result[$id] = array_merge(array_fill_keys(['opened','clicked','unsubscribed'], 0), array_map('intval', $row));
        }
        $events = $this->entityManager->getPDO()->prepare(
            "SELECT e.profile_id,e.event_type,COUNT(DISTINCT e.recipient) AS total FROM nexa_marketing_delivery_event e " .
            "WHERE e.tenant_id=? AND e.service_id=? AND e.profile_id IS NOT NULL AND e.event_type IN ('opened','clicked','unsubscribed') " .
            "AND EXISTS (SELECT 1 FROM nexa_marketing_delivery_message d INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id AND q.service_id=d.service_id WHERE d.profile_id=e.profile_id AND d.tenant_id=e.tenant_id AND d.service_id=e.service_id AND d.provider_message_id=e.provider_message_id AND d.recipient=e.recipient AND q.is_test=0) " .
            "GROUP BY e.profile_id,e.event_type"
        );
        $events->execute([$context->tenantId,$context->serviceId]);
        foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['profile_id']] ??= array_fill_keys(['submitted','delivered','deferred','bounced','complained','failed','opened','clicked','unsubscribed'], 0);
            $result[$row['profile_id']][$row['event_type']] = (int) $row['total'];
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function campaigns(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT c.id,c.name,p.purpose_id AS purposeId,cp.name AS purposeName,p.eligible_count AS eligibleCount,p.suppressed_count AS suppressedCount,p.workflow_status AS status FROM campaign c INNER JOIN nexa_campaign_profile p ON p.campaign_id=c.id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id LEFT JOIN nexa_consent_purpose cp ON cp.id=p.purpose_id AND cp.tenant_id=p.tenant_id AND cp.service_id=p.service_id WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND p.channel='email' AND p.workflow_status<>'archived' ORDER BY c.name");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function purposes(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT id,name,policy_version AS policyVersion FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=? AND is_active=1 AND JSON_CONTAINS(channels_json, JSON_QUOTE('email')) ORDER BY position,name");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, int> */
    private function summary(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) total,SUM(lifecycle_status='draft') drafts,SUM(lifecycle_status='review') review,SUM(lifecycle_status='approved') approved FROM nexa_marketing_email_profile WHERE tenant_id=? AND service_id=? AND lifecycle_status<>'archived'");
        $statement->execute([$context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (int) ($row['total'] ?? 0), 'drafts' => (int) ($row['drafts'] ?? 0), 'review' => (int) ($row['review'] ?? 0), 'approved' => (int) ($row['approved'] ?? 0)];
    }

    private function ensureProfiles(TenantContext $context): void
    {
        $purpose = $this->entityManager->getPDO()->prepare("SELECT id FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=? AND is_active=1 AND purpose_key='marketing_communications' LIMIT 1");
        $purpose->execute([$context->tenantId, $context->serviceId]);
        $purposeId = $purpose->fetchColumn() ?: null;
        $find = $this->entityManager->getPDO()->prepare('SELECT m.id,m.status,m.created_by_id,m.modified_by_id FROM mass_email m LEFT JOIN nexa_marketing_email_profile p ON p.mass_email_id=m.id AND p.tenant_id=m.tenant_id AND p.service_id=m.service_id WHERE m.tenant_id=? AND m.service_id=? AND m.deleted=0 AND p.id IS NULL');
        $find->execute([$context->tenantId, $context->serviceId]);
        $insert = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_marketing_email_profile (id,tenant_id,service_id,mass_email_id,lifecycle_status,purpose_id,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = match ($row['status']) { 'Complete' => 'completed', 'Failed' => 'failed', 'In Process' => 'sending', default => 'draft' };
            $insert->execute([$this->uuid(), $context->tenantId, $context->serviceId, $row['id'], $status, $purposeId, $row['created_by_id'], $row['modified_by_id']]);
        }
    }

    /** @return array<string, mixed> */
    public function versions(string $id, ?int $version = null): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $profile = $this->requireProfile($context, $id);
        if (!$this->recordServices->get('MassEmail')->getEntity($profile['mass_email_id']) ||
            !$this->recordServices->get('EmailTemplate')->getEntity($profile['email_template_id'])) throw new NotFound('Email not found.');
        foreach (['subject', 'body'] as $field) {
            if (!$this->acl->checkField('EmailTemplate', $field)) throw new Forbidden('Email content history is not allowed.');
        }
        $pdo = $this->entityManager->getPDO();
        if ($version !== null) {
            $query = $pdo->prepare('SELECT version_number AS version,subject,preheader,body_html AS bodyHtml,body_text AS bodyText,created_at AS createdAt FROM nexa_marketing_email_version WHERE profile_id=? AND tenant_id=? AND service_id=? AND version_number=?');
            $query->execute([$id, $context->tenantId, $context->serviceId, $version]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new NotFound('Content version not found.');
            return $row;
        }
        $query = $pdo->prepare("SELECT v.version_number AS version,v.subject,v.created_at AS createdAt,v.created_by_id AS createdById,CONCAT_WS(' ',u.first_name,u.last_name) AS createdByName FROM nexa_marketing_email_version v LEFT JOIN user u ON u.id=v.created_by_id AND u.tenant_id=v.tenant_id AND u.service_id=v.service_id WHERE v.profile_id=? AND v.tenant_id=? AND v.service_id=? ORDER BY v.version_number DESC LIMIT 100");
        $query->execute([$id, $context->tenantId, $context->serviceId]);
        return ['currentVersion'=>(int) $profile['version_number'], 'canRestore'=>$this->canWrite(Table::ACTION_EDIT) && in_array($profile['lifecycle_status'], ['draft','review','approved'], true), 'versions'=>$query->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function deliveryMessages(string $id, string $search = '', string $status = '', string $cursor = ''): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $profile = $this->requireProfile($context, $id);
        if (!$this->recordServices->get('MassEmail')->getEntity($profile['mass_email_id'])) throw new NotFound('Email not found.');
        if (!$this->acl->checkScope('Contact', 'read') || !$this->acl->checkField('Contact', 'emailAddress')) throw new Forbidden('Recipient delivery details are not allowed.');
        if (mb_strlen($search) > 255 || ($cursor !== '' && !preg_match('/^[a-f0-9-]{36}$/', $cursor))) throw new BadRequest('Invalid delivery search or cursor.');
        if ($status !== '' && !in_array($status, ['submitting','submitted','delivered','deferred','bounced','complained','failed'], true)) throw new BadRequest('Choose a valid delivery status.');
        $params = [$id, $context->tenantId, $context->serviceId, $profile['mass_email_id']];
        $where = '';
        if ($search !== '') {
            $where .= ' AND d.recipient LIKE ?';
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where .= " ESCAPE '!'";
        }
        if ($status !== '') { $where .= ' AND d.status=?'; $params[] = $status; }
        if ($cursor !== '') { $where .= ' AND d.id>?'; $params[] = $cursor; }
        $query = $this->entityManager->getPDO()->prepare("SELECT d.id,d.recipient,d.status,d.submitted_at AS submittedAt,d.modified_at AS updatedAt,q.target_id AS contactId FROM nexa_marketing_delivery_message d INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id AND q.service_id=d.service_id WHERE d.profile_id=? AND d.tenant_id=? AND d.service_id=? AND q.mass_email_id=? AND q.target_type='Contact' AND q.is_test=0 AND q.deleted=0" . $where . ' ORDER BY d.id LIMIT 100');
        $query->execute($params);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $visible = [];
        foreach ($rows as $row) {
            $contact = $this->entityManager->getEntityById('Contact', $row['contactId']);
            if (!$contact || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId || !$this->acl->checkEntity($contact, 'read')) continue;
            unset($row['contactId']);
            $visible[] = $row;
        }
        return ['items' => $visible, 'nextCursor' => count($rows) === 100 ? $rows[array_key_last($rows)]['id'] : null];
    }

    public function restoreVersion(string $id, int $version): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $profile = $this->requireProfile($context, $id, true);
            if (!in_array($profile['lifecycle_status'], ['draft','review','approved'], true)) throw new BadRequest('Only editable emails can restore content.');
            $content = $this->versions($id, $version);
            $native = $this->recordServices->get('MassEmail')->getEntity($profile['mass_email_id']);
            if (!$native) throw new NotFound('Email not found.');
            $input = (object) $content;
            foreach (['name','campaignId','startAt','fromAddress','fromName','replyToAddress','replyToName'] as $field) $input->$field = $native->get($field);
            $input->purposeId = $profile['purpose_id'];
            $input->deliveryMode = $profile['delivery_mode'];
            $manifest = $this->attachments->versionManifest($id, $version);
            $input->attachmentsIds = array_column($manifest, 'id');
            if ($this->attachments->manifest($input->attachmentsIds) !== $manifest) throw new BadRequest('Historical attachments changed or are unavailable. The version cannot be restored.');
            if ($this->images->manifest((string) $input->bodyHtml) !== $this->images->versionManifest($id, $version)) throw new BadRequest('Historical hosted images changed or are unavailable. The version cannot be restored.');
            $result = $this->save($input, $id);
            $this->insertEvent($context, $id, 'version_restored', $result['version'], ['sourceVersion'=>$version]);
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function requireProfile(TenantContext $context, string $id, bool $lock = false): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT p.*,m.email_template_id FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id WHERE p.id=? AND p.tenant_id=? AND p.service_id=? AND m.deleted=0 LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Marketing email not found.');
        return $row;
    }

    private function insertVersion(TenantContext $context, string $profileId, int $version, string $templateId, array $data): void
    {
        $content = $data['subject'] . "\0" . ($data['preheader'] ?? '') . "\0" . $data['bodyHtml'] . "\0" . ($data['bodyText'] ?? '');
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_marketing_email_version (id,tenant_id,service_id,profile_id,version_number,email_template_id,subject,preheader,body_html,body_text,content_hash,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $profileId, $version, $templateId, $data['subject'], $data['preheader'], $data['bodyHtml'], $data['bodyText'], hash('sha256', $content), $this->user->getId()]);
    }

    private function insertEvent(TenantContext $context, string $profileId, string $type, int $version, array $payload): void
    {
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_marketing_email_event (id,tenant_id,service_id,profile_id,event_type,version_number,payload_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $profileId, $type, $version, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->user->getId()]);
    }

    private function canWrite(string $action): bool { return $this->user->isAdmin() && $this->acl->checkScope('MassEmail', $action) && $this->acl->checkScope('EmailTemplate', $action); }
    private function requireAccess(string $action): void { if (!$this->acl->checkScope('MassEmail', $action) || !$this->acl->checkScope('EmailTemplate', $action)) throw new Forbidden('Marketing email access is not allowed.'); if ($action !== Table::ACTION_READ && !$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can configure marketing email.'); }
    private function dateTime(mixed $value): string { $value = trim((string) $value); if ($value === '') return gmdate('Y-m-d H:i:s', time() + 86400); $date = date_create_immutable($value); if (!$date) throw new BadRequest('Enter a valid schedule date and time.'); return $date->format('Y-m-d H:i:s'); }
    private function email(mixed $value, bool $required): ?string { $value = trim((string) $value); if ($value === '' && !$required) return null; if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new BadRequest($required ? 'Enter a valid sender email address.' : 'Enter a valid reply-to email address.'); return mb_strtolower($value); }
    private function text(mixed $value, int $length): ?string { $value = trim((string) $value); return $value === '' ? null : mb_substr($value, 0, $length); }
    /** @return string[] */ private function decodeIds(mixed $value): array { $decoded = json_decode((string) $value, true); return is_array($decoded) ? array_values(array_unique(array_filter(array_map('strval', $decoded)))) : []; }
    private function uuid(): string { $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)); }
}
