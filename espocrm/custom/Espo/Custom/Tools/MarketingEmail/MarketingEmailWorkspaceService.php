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
    ) {}

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
            ],
            'delivery' => $delivery,
            'deliveryEnabled' => $delivery['ready'],
            'deliveryMessage' => $delivery['ready'] ? 'Delivery prerequisites are ready.' : 'Scheduling unlocks after a verified sending domain and marketing delivery provider are configured.',
        ];
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
            if ($isNew) {
                $template = $this->recordServices->get('EmailTemplate')->create((object) [
                    'name' => $data['name'], 'subject' => $data['subject'], 'body' => $data['bodyHtml'],
                    'isHtml' => true, 'oneOff' => false,
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
                $profile = $this->requireProfile($context, $id);
                if (!in_array($profile['lifecycle_status'], ['draft', 'review', 'approved'], true)) throw new BadRequest('This email can no longer be edited.');
                $nativeId = (string) $profile['mass_email_id'];
                $templateId = (string) $profile['email_template_id'];
                $version = (int) $profile['version_number'] + 1;
                $this->recordServices->get('EmailTemplate')->update($templateId, (object) ['name' => $data['name'], 'subject' => $data['subject'], 'body' => $data['bodyHtml']], UpdateParams::create());
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
            $this->insertEvent($context, (string) $id, $isNew ? 'created' : 'updated', $version, ['nativeId' => $nativeId]);
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
        $profile = $this->requireProfile($context, $id);
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

        $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status=?,submitted_by_id=IF(?='review',?,submitted_by_id),submitted_at=IF(?='review',NOW(6),submitted_at),approved_by_id=IF(?='approved',?,NULL),approved_at=IF(?='approved',NOW(6),NULL),modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?");
        $statement->execute([$next, $next, $this->user->getId(), $next, $next, $this->user->getId(), $next, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        $this->insertEvent($context, $id, $next, (int) $profile['version_number'], ['previousStatus' => $current]);
        return ['id' => $id, 'status' => $next];
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
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.id,p.mass_email_id AS massEmailId,p.delivery_mode AS deliveryMode,p.lifecycle_status AS lifecycleStatus,p.purpose_id AS purposeId,p.preheader,p.version_number AS version,p.submitted_at AS submittedAt,p.approved_at AS approvedAt,m.name,m.status AS nativeStatus,m.start_at AS startAt,m.from_address AS fromAddress,m.from_name AS fromName,m.reply_to_address AS replyToAddress,m.reply_to_name AS replyToName,m.campaign_id AS campaignId,m.email_template_id AS emailTemplateId,m.modified_at AS modifiedAt,t.subject,t.body AS bodyHtml,c.name AS campaignName,cp.name AS purposeName,(SELECT COUNT(*) FROM email_queue_item q WHERE q.mass_email_id=m.id AND q.tenant_id=m.tenant_id AND q.service_id=m.service_id AND q.deleted=0) AS queuedCount FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id INNER JOIN email_template t ON t.id=m.email_template_id AND t.tenant_id=m.tenant_id AND t.service_id=m.service_id LEFT JOIN campaign c ON c.id=m.campaign_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id LEFT JOIN nexa_consent_purpose cp ON cp.id=p.purpose_id AND cp.tenant_id=p.tenant_id AND cp.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND m.deleted=0 AND p.lifecycle_status<>'archived' ORDER BY p.modified_at DESC");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array { $row['version'] = (int) $row['version']; $row['queuedCount'] = (int) $row['queuedCount']; return $row; }, $statement->fetchAll(PDO::FETCH_ASSOC));
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
    private function requireProfile(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT p.*,m.email_template_id FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id WHERE p.id=? AND p.tenant_id=? AND p.service_id=? AND m.deleted=0 LIMIT 1');
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
