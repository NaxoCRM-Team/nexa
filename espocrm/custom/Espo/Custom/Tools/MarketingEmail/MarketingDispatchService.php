<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config\ApplicationConfig;
use Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryProviderException;
use Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryRequest;
use Espo\Custom\Tools\MarketingEmail\Delivery\PostalDeliveryProvider;
use Espo\Entities\EmailTemplate;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\EmailQueueItem;
use Espo\Modules\Crm\Entities\MassEmail;
use Espo\ORM\Entity;
use Espo\Tools\EmailTemplate\Data as TemplateData;
use Espo\Tools\EmailTemplate\Params as TemplateParams;
use Espo\Tools\EmailTemplate\Processor as TemplateProcessor;
use PDO;

/** Consent-aware dispatcher over native MassEmail, EmailTemplate and EmailQueueItem records. */
final class MarketingDispatchService
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private MarketingDeliveryService $delivery,
        private PostalDeliveryProvider $provider,
        private TemplateProcessor $templateProcessor,
        private ApplicationConfig $applicationConfig,
        private User $user,
        private MarketingUnsubscribeService $unsubscribe,
        private \Espo\Core\Acl $acl,
        private \Espo\Modules\Crm\Tools\MassEmail\QueueCreator $queueCreator,
        private MarketingAttachmentService $attachments,
        private MarketingImageService $images,
        private MarketingEmailWorkspaceService $workspace,
        private MarketingParameterTemplate $parameters,
        private MarketingContentIntegrity $contentIntegrity,
        private MarketingIntegrationAccess $integrationAccess,
        private MarketingConditionalContentService $conditionalContent,
    ) {}

    /** @return array{id:string,status:string,eligibleCount:int,scheduledAt:string} */
    public function schedule(string $profileId): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can schedule marketing email.');
        }
        $context = $this->tenantContextStore->require();
        $profile = $this->profile($context, $profileId, true);
        if ($profile['delivery_mode'] === 'single_send') throw new BadRequest('Use the single-send API to select exactly one Contact.');
        if ($profile['lifecycle_status'] !== 'approved') {
            throw new BadRequest('Approve this marketing email before scheduling it.');
        }
        if (!$this->delivery->readiness()['ready']) {
            throw new BadRequest('Complete provider and sending-domain setup before scheduling.');
        }
        if ($profile['campaign_status'] !== 'active') {
            throw new BadRequest('Activate the selected campaign before scheduling its email.');
        }
        $this->requireVerifiedSender($context, (string) $profile['from_address']);
        $template = $this->entityManager->getEntityById('EmailTemplate', $profile['email_template_id']);
        if (!$template instanceof EmailTemplate) throw new NotFound('Email template not found.');
        $this->contentIntegrity->requireSnapshot($profile, $template);
        $this->parameters->requireResolved((string) $template->get('subject'), (string) $template->get('body'), (string) ($profile['preheader'] ?? ''));
        $this->attachments->approvedFiles($profile, $template, true);
        $this->images->requireApproved($profile, $template);
        $eligibleCount = $this->eligibleCount($context, $profile);
        if ($eligibleCount === 0) {
            throw new BadRequest('No contacts are currently eligible for this marketing email.');
        }
        $this->requireCapacity($context, $eligibleCount);

        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $update = $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='scheduled',modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status='approved'");
            $update->execute([$this->user->getId(), $profileId, $context->tenantId, $context->serviceId]);
            if ($update->rowCount() !== 1) {
                throw new BadRequest('This marketing email is no longer available for scheduling.');
            }
            $native = $pdo->prepare("UPDATE mass_email SET status='Nexa Scheduled',modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0");
            $native->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
            $this->event($context, $profileId, 'scheduled', (int) $profile['version_number'], ['eligibleCount' => $eligibleCount, 'scheduledAt' => $profile['start_at']]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $profileId, 'status' => 'scheduled', 'eligibleCount' => $eligibleCount, 'scheduledAt' => (string) $profile['start_at']];
    }

    /** Reuse approved content while retaining one durable native dispatch per scoped external request key. */
    public function reusableSend(string $sourceId, string $contactId, string $key, int $version, ?\stdClass $parameters = null): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) || $version < 1 || $contactId === '' || strlen($contactId) > 24) throw new BadRequest('Provide a Contact, source version and request key of 8 to 128 letters, digits or . _ : - characters.');
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT nexa_reusable_send');
        try {
            if ($this->user->isAdmin()) {
                $source = $this->requireReviewAccess($context,$sourceId);
            } else {
                $source = $this->profile($context,$sourceId,true);
                $this->integrationAccess->requireGrant($sourceId,$version);
                $nativeSource = $this->entityManager->getEntityById('MassEmail',$source['mass_email_id']);
                if (!$nativeSource || !$this->acl->checkEntity($nativeSource,'read')) throw new Forbidden('The approved source is not available.');
            }
            $saved = $this->workspace->versions($sourceId,$version);
            $resolved = $this->parameters->resolve($saved['subject'],$saved['bodyHtml'],(string) ($saved['preheader'] ?? ''),$parameters ?? new \stdClass());
            $hash = hash('sha256', $key);
            // The unique scoped key serializes competing requests even when they reference different sources.
            $pdo->prepare('INSERT IGNORE INTO nexa_marketing_send_request (tenant_id,service_id,request_hash,source_profile_id,source_version,contact_id,created_by_id,parameter_fingerprint) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$context->tenantId,$context->serviceId,$hash,$sourceId,$version,$contactId,$this->user->getId(),$resolved['fingerprint']]);
            $query = $pdo->prepare('SELECT * FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND request_hash=? FOR UPDATE');
            $query->execute([$context->tenantId,$context->serviceId,$hash]); $request = $query->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['source_profile_id'] !== $sourceId || (int) $request['source_version'] !== $version || $request['contact_id'] !== $contactId) throw new BadRequest('This request key already belongs to another source, version or Contact.');
            $this->integrationAccess->requireRequestOwner($request);
            if (($request['parameter_fingerprint'] !== null && !hash_equals($request['parameter_fingerprint'],$resolved['fingerprint'])) || ($request['parameter_fingerprint'] === null && (array) ($parameters ?? new \stdClass()))) throw new BadRequest('This request key already belongs to different parameter values.');
            if ($request['profile_id']) {
                $result = $this->dispatchSingleSend($request['profile_id'],$contactId,$this->user->isAdmin() ? null : $request);
                if ($ownsTransaction) $pdo->commit();
                else $pdo->exec('RELEASE SAVEPOINT nexa_reusable_send');
                return $result + ['sourceId'=>$sourceId,'sourceVersion'=>$version];
            }
            if ($source['lifecycle_status'] !== 'approved' || (int) $source['version_number'] !== $version) throw new BadRequest('Use the current approved source version.');
            $recent = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND source_profile_id=? AND created_at>DATE_SUB(NOW(6),INTERVAL 1 MINUTE)');
            $recent->execute([$context->tenantId,$context->serviceId,$sourceId]);
            if ((int) $recent->fetchColumn() > 60) throw new BadRequest('This approved source has reached its 60-request-per-minute limit.');
            if ($source['campaign_status'] !== 'active' || !$this->eligibleRecipient($context,$source,$contactId)) throw new BadRequest('The Contact must be eligible and enrolled in an active campaign.');
            if (!$this->delivery->readiness()['ready']) throw new BadRequest('Complete provider and sending-domain setup before sending.');
            $this->requireVerifiedSender($context,(string) $source['from_address']); $this->requireCapacity($context,1);
            $template = $this->entityManager->getEntityById('EmailTemplate',$source['email_template_id']);
            $mass = $this->entityManager->getEntityById('MassEmail',$source['mass_email_id']);
            $contact = $this->entityManager->getEntityById('Contact',$contactId);
            if (!$template instanceof EmailTemplate || !$mass instanceof MassEmail || !$contact || !$this->acl->checkEntity($template,'read') || !$this->acl->checkEntity($contact,'read') || !$this->acl->checkScope('Contact','read') || !$this->acl->checkField('EmailTemplate','subject') || !$this->acl->checkField('EmailTemplate','body')) throw new Forbidden('Source content or recipient is not available.');
            $this->contentIntegrity->requireSnapshot($source, $template, $mass);
            if ($saved['subject'] !== $template->get('subject') || $saved['bodyHtml'] !== $template->get('body') || ($saved['preheader'] ?? '') !== ($source['preheader'] ?? '')) throw new BadRequest('Source content changed after approval. Save and approve it again.');
            $this->attachments->approvedFiles($source,$template,true); $this->images->requireApproved($source,$template);
            $draft = $this->user->isAdmin() ? $this->workspace->save((object) [
                'name'=>$mass->get('name') . ' / ' . substr($hash,0,12), 'deliveryMode'=>'single_send',
                'campaignId'=>$source['campaign_id'], 'purposeId'=>$source['purpose_id'],
                'subject'=>$resolved['subject'], 'bodyHtml'=>$resolved['bodyHtml'], 'preheader'=>$resolved['preheader'],
                'fromAddress'=>$mass->get('fromAddress'), 'fromName'=>$mass->get('fromName'),
                'replyToAddress'=>$mass->get('replyToAddress'), 'replyToName'=>$mass->get('replyToName'),
                'attachmentsIds'=>$this->attachments->templateIds($template),
            ]) : $this->workspace->approvedIntegrationCopy($sourceId,$version,$hash,$parameters ?? new \stdClass());
            if ($this->user->isAdmin()) {
                $this->workspace->transition($draft['id'],(object) ['action'=>'submit']);
                $this->workspace->transition($draft['id'],(object) ['action'=>'approve']);
            }
            $request['profile_id'] = $draft['id'];
            $result = $this->dispatchSingleSend($draft['id'],$contactId,$this->user->isAdmin() ? null : $request);
            $pdo->prepare('UPDATE nexa_marketing_send_request SET profile_id=? WHERE tenant_id=? AND service_id=? AND request_hash=?')
                ->execute([$draft['id'],$context->tenantId,$context->serviceId,$hash]);
            $this->event($context,$draft['id'],'reusable_send_queued',1,['sourceId'=>$sourceId,'sourceVersion'=>$version,'requestHash'=>$hash,'parameterFingerprint'=>$resolved['fingerprint'],'queueItemId'=>$result['queueItemId']]);
            if ($ownsTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT nexa_reusable_send');
            return $result + ['sourceId'=>$sourceId,'sourceVersion'=>$version];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) {$pdo->exec('ROLLBACK TO SAVEPOINT nexa_reusable_send'); $pdo->exec('RELEASE SAVEPOINT nexa_reusable_send');}
            throw $error;
        }
    }

    /** One approved profile is one dispatch identity. Locking reads and a conditional state claim prevent duplicate sends. */
    public function singleSend(string $id, string $contactId): array
    {
        return $this->dispatchSingleSend($id,$contactId);
    }

    private function dispatchSingleSend(string $id, string $contactId, ?array $request = null): array
    {
        if ($contactId === '' || strlen($contactId) > 64) throw new BadRequest('Choose a valid Contact.');
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            // requireReviewAccess acquires SELECT ... FOR UPDATE on the profile inside this transaction.
            if ($request === null) {
                $profile = $this->requireReviewAccess($context,$id);
            } else {
                $this->integrationAccess->requireRequestOwner($request);
                if ($this->user->isAdmin() || ($request['profile_id'] ?? null) !== $id || ($request['contact_id'] ?? null) !== $contactId) throw new Forbidden('Invalid integration dispatch identity.');
                $profile = $this->profile($context,$id,true);
                if ($profile['created_by_id'] !== $this->user->getId()) throw new Forbidden('This integration copy belongs to another caller.');
            }
            if ($profile['delivery_mode'] !== 'single_send') throw new BadRequest('This email is not configured for single-send delivery.');
            $contact = $this->entityManager->getEntityById('Contact', $contactId);
            if (!$contact || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId || !$this->acl->checkScope('Contact', 'read') || !$this->acl->checkEntity($contact, 'read')) throw new NotFound('Contact not found.');
            $existing = $pdo->prepare("SELECT id,target_id,status FROM email_queue_item WHERE mass_email_id=? AND tenant_id=? AND service_id=? AND is_test=0 ORDER BY created_at,id LIMIT 1 FOR UPDATE");
            $existing->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['target_id'] !== $contactId) throw new BadRequest('This single-send email already belongs to another Contact. Create a new approved email for another send.');
                if ($ownsTransaction) $pdo->commit();
                return ['id' => $id, 'queueItemId' => $row['id'], 'status' => $row['status'], 'replayed' => true];
            }
            if ($profile['lifecycle_status'] !== 'approved') throw new BadRequest('Approve this email before requesting single-send delivery.');
            if ($profile['campaign_status'] !== 'active') throw new BadRequest('Activate the selected campaign before sending its email.');
            if (!$this->delivery->readiness()['ready']) throw new BadRequest('Complete provider and sending-domain setup before sending.');
            $this->requireVerifiedSender($context, (string) $profile['from_address']);
            if (!$this->eligibleRecipient($context, $profile, $contactId)) throw new BadRequest('This Contact must be enrolled, consented and eligible for email delivery.');
            $this->requireCapacity($context, 1);
            $template = $this->entityManager->getEntityById('EmailTemplate', $profile['email_template_id']);
            if (!$template instanceof EmailTemplate || !$this->acl->checkEntity($template, 'read')) throw new NotFound('Email template not found.');
            $this->contentIntegrity->requireSnapshot($profile, $template);
            $this->parameters->requireResolved((string) $template->get('subject'), (string) $template->get('body'), (string) ($profile['preheader'] ?? ''));
            if (!$this->acl->checkField('EmailTemplate', 'subject') || !$this->acl->checkField('EmailTemplate', 'body')) throw new Forbidden('Email content is not available for delivery.');
            $this->attachments->approvedFiles($profile, $template, true);
            $this->images->requireApproved($profile, $template);
            // Claim the one-shot profile atomically before creating any recipient queue item.
            $claim = $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='sending',modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status='approved' AND delivery_mode='single_send'");
            $claim->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            if ($claim->rowCount() !== 1) throw new BadRequest('This single-send email has already been claimed.');
            $item = $this->entityManager->getNewEntity(EmailQueueItem::ENTITY_TYPE);
            $item->set(['massEmailId' => $profile['mass_email_id'], 'status' => EmailQueueItem::STATUS_PENDING, 'targetId' => $contactId, 'targetType' => 'Contact', 'isTest' => false]);
            $this->entityManager->saveEntity($item);
            $pdo->prepare("UPDATE mass_email SET status='Nexa Sending',modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?")
                ->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
            $this->event($context, $id, 'single_send_queued', (int) $profile['version_number'], ['contactId' => $contactId, 'queueItemId' => $item->getId()]);
            if ($ownsTransaction) $pdo->commit();
            return ['id' => $id, 'queueItemId' => $item->getId(), 'status' => EmailQueueItem::STATUS_PENDING, 'replayed' => false];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return array{id:string,status:string} */
    public function control(string $id, string $action): array
    {
        if (!$this->user->isAdmin()) throw new Forbidden('Only a tenant administrator can control delivery.');
        if (!in_array($action, ['pause', 'resume'], true)) throw new BadRequest('Unknown delivery action.');
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $profile = $this->profile($context, $id, true);
            $current = $profile['lifecycle_status'];
            if ($action === 'pause') {
                if (!in_array($current, ['scheduled', 'sending'], true)) throw new BadRequest('Only scheduled or sending emails can be paused.');
                $next = 'paused';
            } else {
                if ($current !== 'paused') throw new BadRequest('Only paused emails can resume.');
                $inFlight = $pdo->prepare("SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND status='Sending' AND deleted=0");
                $inFlight->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
                if ((int) $inFlight->fetchColumn() > 0) throw new BadRequest('A submission is still awaiting confirmation. Review Postal before resuming.');
                if (!$this->delivery->readiness()['ready']) throw new BadRequest('Complete delivery setup before resuming.');
                if ($profile['campaign_status'] !== 'active') throw new BadRequest('Activate the campaign before resuming.');
                $this->requireVerifiedSender($context, (string) $profile['from_address']);
                $queue = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND is_test=0 AND deleted=0');
                $queue->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
                $next = (int) $queue->fetchColumn() > 0 ? 'sending' : 'scheduled';
            }
            $pdo->prepare('UPDATE nexa_marketing_email_profile SET lifecycle_status=?,modified_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?')
                ->execute([$next, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            $pdo->prepare('UPDATE mass_email SET status=?,modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?')
                ->execute(['Nexa ' . ucfirst($next), $profile['mass_email_id'], $context->tenantId, $context->serviceId]);
            $this->event($context, $id, $action === 'pause' ? 'paused' : 'resumed', (int) $profile['version_number'], ['previousStatus'=>$current,'status'=>$next]);
            if ($ownsTransaction) $pdo->commit();
            return ['id'=>$id,'status'=>$next];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function requireReviewAccess(TenantContext $context, string $id): array
    {
        if (!$this->user->isAdmin() || !$this->acl->checkScope('MassEmail', 'edit')) throw new Forbidden('Only an authorized tenant administrator can review delivery.');
        $profile = $this->profile($context, $id, true);
        $native = $this->entityManager->getEntityById('MassEmail', $profile['mass_email_id']);
        if (!$native || !$this->acl->checkEntity($native, 'edit')) throw new Forbidden('This email cannot be reviewed.');
        return $profile;
    }

    public function reviewSubmissions(string $id): array
    {
        $context = $this->tenantContextStore->require();
        $profile = $this->requireReviewAccess($context, $id);
        $query = $this->entityManager->getPDO()->prepare("SELECT d.id,d.recipient,d.status,d.provider_message_id AS providerMessageId,d.created_at AS createdAt FROM nexa_marketing_delivery_message d INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id AND q.service_id=d.service_id WHERE d.profile_id=? AND d.tenant_id=? AND d.service_id=? AND q.mass_email_id=? AND q.status='Sending' AND q.deleted=0 ORDER BY d.created_at,d.id LIMIT 100");
        $query->execute([$id, $context->tenantId, $context->serviceId, $profile['mass_email_id']]);
        return ['items' => $query->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function resolveSubmission(string $id, \stdClass $input): array
    {
        $decision = (string) ($input->decision ?? '');
        $note = trim((string) ($input->evidenceNote ?? ''));
        $messageId = trim((string) ($input->providerMessageId ?? ''));
        if (!in_array($decision, ['accepted', 'not_accepted'], true)) throw new BadRequest('Choose a provider evidence outcome.');
        if (mb_strlen($note) < 10 || mb_strlen($note) > 2000) throw new BadRequest('Record provider evidence in 10 to 2,000 characters.');
        if ($decision === 'accepted' && ($messageId === '' || strlen($messageId) > 255 || preg_match('/[\x00-\x1f]/', $messageId))) throw new BadRequest('Enter the confirmed Postal message ID.');
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $profile = $this->requireReviewAccess($context, $id);
            if ($profile['lifecycle_status'] !== 'paused') throw new BadRequest('Pause this email before reviewing submissions.');
            $query = $pdo->prepare("SELECT d.*,q.status AS queue_status FROM nexa_marketing_delivery_message d INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id AND q.service_id=d.service_id WHERE d.id=? AND d.profile_id=? AND d.tenant_id=? AND d.service_id=? AND q.mass_email_id=? AND q.deleted=0 FOR UPDATE");
            $query->execute([(string) ($input->messageId ?? ''), $id, $context->tenantId, $context->serviceId, $profile['mass_email_id']]);
            $message = $query->fetch(PDO::FETCH_ASSOC);
            if (!$message) throw new NotFound('Submission not found.');
            if ($message['queue_status'] !== 'Sending') throw new BadRequest('This submission has already been resolved.');
            if ($message['status'] === 'submitting' && !str_starts_with((string) $message['last_error'], 'Postal acceptance is uncertain.') && strtotime($message['modified_at']) > time() - 900) {
                throw new BadRequest('This submission is still in flight. Wait for its response before reviewing it.');
            }
            if ($decision === 'not_accepted') {
                if ($message['status'] !== 'submitting' || $message['provider_message_id']) throw new BadRequest('A confirmed provider submission cannot be marked not accepted.');
                $pdo->prepare("UPDATE nexa_marketing_delivery_message SET status='failed',last_error='Provider non-acceptance confirmed by administrator',reconciled_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?")->execute([$message['id'], $context->tenantId, $context->serviceId]);
                $pdo->prepare("UPDATE nexa_marketing_monthly_usage SET accepted_count=GREATEST(accepted_count-1,0) WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(?,'%Y-%m-01')")->execute([$context->tenantId, $context->serviceId, $message['created_at']]);
            } else {
                if ($message['provider_message_id'] && $message['provider_message_id'] !== $messageId) throw new BadRequest('The provider message ID conflicts with existing evidence.');
                $conflict = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=? AND provider_message_id=? AND id<>?');
                $conflict->execute([$context->tenantId, $context->serviceId, $messageId, $message['id']]);
                if ((int) $conflict->fetchColumn() > 0) throw new BadRequest('This provider message ID belongs to another submission.');
                $pdo->prepare("UPDATE nexa_marketing_delivery_message SET provider_message_id=?,status=IF(status='submitting','submitted',status),last_error=NULL,submitted_at=COALESCE(submitted_at,NOW(6)),modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?")->execute([$messageId, $message['id'], $context->tenantId, $context->serviceId]);
            }
            $pdo->prepare("UPDATE email_queue_item SET status=?,sent_at=IF(?='Sent',COALESCE(sent_at,NOW()),sent_at) WHERE id=? AND tenant_id=? AND service_id=? AND status='Sending'")->execute([$decision === 'accepted' ? 'Sent' : 'Failed', $decision === 'accepted' ? 'Sent' : 'Failed', $message['email_queue_item_id'], $context->tenantId, $context->serviceId]);
            $this->event($context, $id, 'submission_review_resolved', (int) $profile['version_number'], ['messageId' => $message['id'], 'decision' => $decision, 'providerMessageId' => $decision === 'accepted' ? $messageId : null, 'evidenceNote' => $note]);
            if ($ownsTransaction) $pdo->commit();
            return ['resolved' => true, 'decision' => $decision];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function sendTest(string $id, string $contactId): array
    {
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $profile = $this->requireReviewAccess($context, $id);
            if ($profile['lifecycle_status'] !== 'approved') throw new BadRequest('Approve the saved email before sending a provider test.');
            if ($this->user->get('tenantId') !== $context->tenantId || $this->user->get('serviceId') !== $context->serviceId || !filter_var($this->user->get('emailAddress'), FILTER_VALIDATE_EMAIL)) throw new Forbidden('A tenant-owned administrator email address is required for test delivery.');
            if (!$this->delivery->readiness()['ready']) throw new BadRequest('Complete Postal and sending-domain setup before testing delivery.');
            $this->requireVerifiedSender($context, $profile['from_address']);
            $this->requireCapacity($context, 1);
            $contact = $this->entityManager->getEntityById('Contact', $contactId);
            if (!$contact || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId || !$this->acl->checkScope('Contact', 'read') || !$this->acl->checkEntity($contact, 'read')) throw new NotFound('Preview Contact not found.');
            $recent = $pdo->prepare("SELECT COUNT(*) FROM email_queue_item WHERE mass_email_id=? AND tenant_id=? AND service_id=? AND is_test=1 AND target_type='User' AND target_id=? AND deleted=0 AND (status='Sending' OR created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))");
            $recent->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId, $this->user->getId()]);
            if ((int) $recent->fetchColumn() > 0) throw new BadRequest('Wait one minute between tests and resolve any unconfirmed test first.');
            $massEmail = $this->entityManager->getEntityById('MassEmail', $profile['mass_email_id']);
            $template = $this->entityManager->getEntityById('EmailTemplate', $profile['email_template_id']);
            if (!$massEmail instanceof MassEmail || !$template instanceof EmailTemplate || !$this->acl->checkEntity($template, 'read')) throw new NotFound('Email template not found.');
            if (!$this->acl->checkField('EmailTemplate', 'subject') || !$this->acl->checkField('EmailTemplate', 'body')) throw new Forbidden('Email content is not available for test delivery.');
            $testAttachments = $this->attachments->approvedFiles($profile, $template, true);
            $this->images->requireApproved($profile, $template);
            $this->queueCreator->create($massEmail, true, [$this->user]);
            $queue = $pdo->prepare("SELECT id FROM email_queue_item WHERE mass_email_id=? AND tenant_id=? AND service_id=? AND is_test=1 AND target_type='User' AND target_id=? AND status='Pending' AND deleted=0 ORDER BY created_at DESC,id DESC LIMIT 1");
            $queue->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId, $this->user->getId()]);
            $queueId = $queue->fetchColumn();
            if (!$queueId) throw new BadRequest('The administrator email is invalid or opted out of native delivery.');
            if (!$this->reserveItem($context, $profile, $queueId, $this->user->get('emailAddress'), true)) throw new BadRequest('The test could not reserve monthly delivery capacity.');
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        try {
            $this->dispatchItem($context, $profile, $massEmail, $template, $queueId, $contactId, $this->user, $testAttachments);
        } catch (\Throwable $error) {
            $this->holdUncertainSubmission($context, $profile, $queueId);
            throw $error;
        }
        $status = $pdo->prepare('SELECT status FROM nexa_marketing_delivery_message WHERE email_queue_item_id=? AND tenant_id=? AND service_id=?');
        $status->execute([$queueId, $context->tenantId, $context->serviceId]);
        $outcome = (string) $status->fetchColumn();
        $this->event($context, $id, 'test_delivery', (int) $profile['version_number'], ['queueItemId' => $queueId, 'status' => $outcome]);
        return ['status' => $outcome, 'recipient' => $this->user->get('emailAddress')];
    }

    /** Process due profiles for the current tenant and service. */
    public function runScheduled(): void
    {
        $context = $this->tenantContextStore->require();
        $this->pauseStaleSubmissions($context);
        $statement = $this->entityManager->getPDO()->prepare("SELECT id FROM nexa_marketing_email_profile WHERE tenant_id=? AND service_id=? AND lifecycle_status IN ('scheduled','sending') ORDER BY created_at LIMIT 20");
        $statement->execute([$context->tenantId, $context->serviceId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $profileId) {
            $profile = $this->profile($context, (string) $profileId, false);
            if ($profile['lifecycle_status'] === 'scheduled' && strtotime((string) $profile['start_at']) > time()) {
                continue;
            }
            try {
                $this->prepareQueue($context, $profile);
                $this->dispatchBatch($context, $profile);
            } catch (\Throwable $e) {
                $this->failProfile($context, $profile, $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $profile */
    private function prepareQueue(TenantContext $context, array &$profile): void
    {
        if ($profile['lifecycle_status'] !== 'scheduled') {
            return;
        }
        $this->requireVerifiedSender($context, (string) $profile['from_address']);
        $recipients = $this->eligibleRecipients($context, $profile);
        $this->requireCapacity($context, count($recipients));
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $locked = $this->profile($context, (string) $profile['id'], true);
            if ($locked['lifecycle_status'] !== 'scheduled') {
                if ($ownsTransaction) $pdo->rollBack();
                return;
            }
            $exists = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND target_id=? AND target_type=\'Contact\' AND deleted=0');
            foreach ($recipients as $recipient) {
                $exists->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id'], $recipient['id']]);
                if ((int) $exists->fetchColumn() > 0) {
                    continue;
                }
                $item = $this->entityManager->getNewEntity(EmailQueueItem::ENTITY_TYPE);
                $item->set(['massEmailId' => $profile['mass_email_id'], 'status' => EmailQueueItem::STATUS_PENDING, 'targetId' => $recipient['id'], 'targetType' => 'Contact', 'isTest' => false]);
                $this->entityManager->saveEntity($item);
            }
            $status = $recipients ? 'sending' : 'completed';
            $update = $pdo->prepare('UPDATE nexa_marketing_email_profile SET lifecycle_status=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status=\'scheduled\'');
            $update->execute([$status, $profile['id'], $context->tenantId, $context->serviceId]);
            $native = $pdo->prepare('UPDATE mass_email SET status=?,modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?');
            $native->execute([$recipients ? 'Nexa Sending' : MassEmail::STATUS_COMPLETE, $profile['mass_email_id'], $context->tenantId, $context->serviceId]);
            $this->event($context, (string) $profile['id'], 'queue_created', (int) $profile['version_number'], ['recipientCount' => count($recipients)]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            $profile['lifecycle_status'] = $status;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $profile */
    private function dispatchBatch(TenantContext $context, array $profile): void
    {
        if ($profile['lifecycle_status'] !== 'sending') {
            return;
        }
        $massEmail = $this->entityManager->getEntityById(MassEmail::ENTITY_TYPE, (string) $profile['mass_email_id']);
        $template = $this->entityManager->getEntityById(EmailTemplate::ENTITY_TYPE, (string) $profile['email_template_id']);
        if (!$massEmail instanceof MassEmail || !$template instanceof EmailTemplate) {
            throw new DeliveryProviderException('The native email or template record is unavailable.');
        }

        $statement = $this->entityManager->getPDO()->prepare("SELECT q.id,q.target_id FROM email_queue_item q LEFT JOIN nexa_marketing_delivery_message d ON d.tenant_id=q.tenant_id AND d.service_id=q.service_id AND d.email_queue_item_id=q.id WHERE q.tenant_id=? AND q.service_id=? AND q.mass_email_id=? AND q.is_test=0 AND q.status='Pending' AND q.deleted=0 AND d.id IS NULL ORDER BY q.created_at,q.id LIMIT " . self::BATCH_SIZE);
        $statement->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->dispatchItem($context, $profile, $massEmail, $template, (string) $row['id'], (string) $row['target_id']);
        }
        $remaining = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND is_test=0 AND status IN ('Pending','Sending') AND deleted=0");
        $remaining->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
        if ((int) $remaining->fetchColumn() === 0) {
            $failed = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND is_test=0 AND status='Failed' AND deleted=0");
            $failed->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
            if ((int) $failed->fetchColumn() > 0) {
                $this->failProfile($context, $profile, 'One or more recipients could not be submitted.');
            } else {
                $this->completeProfile($context, $profile);
            }
        }
    }

    private function pauseStaleSubmissions(TenantContext $context): void
    {
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $query = $pdo->prepare("SELECT p.id,p.mass_email_id,p.version_number FROM nexa_marketing_email_profile p
                WHERE p.tenant_id=? AND p.service_id=? AND p.lifecycle_status IN ('sending','approved')
                AND EXISTS (SELECT 1 FROM nexa_marketing_delivery_message d
                    WHERE d.profile_id=p.id AND d.tenant_id=p.tenant_id AND d.service_id=p.service_id
                    AND d.status='submitting' AND d.modified_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE))
                LIMIT 20 FOR UPDATE");
            $query->execute([$context->tenantId, $context->serviceId]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $profile) {
                $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='paused',modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?")
                    ->execute([$profile['id'], $context->tenantId, $context->serviceId]);
                $pdo->prepare("UPDATE mass_email SET status='Nexa Paused',modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?")
                    ->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
                $this->event($context, $profile['id'], 'submission_review_required', (int) $profile['version_number'],
                    ['message' => 'A submission has no confirmed provider response. Review Postal before retrying to avoid duplicate delivery.']);
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $profile */
    private function dispatchItem(TenantContext $context, array $profile, MassEmail $massEmail, EmailTemplate $template, string $queueItemId, string $contactId, ?User $testUser = null, ?array $validatedAttachments = null): void
    {
        $this->contentIntegrity->requireSnapshot($profile, $template, $massEmail);
        $this->conditionalContent->validate((string) $template->get('subject'), (string) $template->get('body'));
        $this->parameters->requireResolved((string) $template->get('subject'), (string) $template->get('body'), (string) ($profile['preheader'] ?? ''));
        // Tests retain the exact bytes validated before reserving delivery capacity.
        $files = $validatedAttachments ?? $this->attachments->approvedFiles($profile, $template, $testUser !== null);
        $this->images->requireApproved($profile, $template);
        $recipient = $testUser ? ['email' => $testUser->get('emailAddress')] : $this->eligibleRecipient($context, $profile, $contactId);
        if ($recipient === null) {
            $this->markQueueFailed($context, $queueItemId, 'Recipient became ineligible before delivery.');
            return;
        }
        $contact = $this->entityManager->getEntityById('Contact', $contactId);
        if (!$contact instanceof Entity) {
            $this->markQueueFailed($context, $queueItemId, 'Contact is unavailable.');
            return;
        }
        $rendered = $this->templateProcessor->process($template, TemplateParams::create()->withApplyAcl($testUser !== null)->withCopyAttachments(false), TemplateData::create()->withParent($contact));
        $unsubscribeUrl = rtrim($this->applicationConfig->getSiteUrl(), '/') . '/api/v1/Nexa/public/marketing-unsubscribe/' . rawurlencode($queueItemId) . '/' . $this->unsubscribe->token($queueItemId);
        $body = str_replace(['{queueItemId}', '{optOutUrl}', '{optOutLink}'], [$queueItemId, $unsubscribeUrl, '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Unsubscribe</a>'], $rendered->getBody());
        if (!str_contains($body, '/Nexa/public/marketing-unsubscribe/')) {
            $body .= $rendered->isHtml() ? '<br><br><a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Unsubscribe</a>' : "\n\n" . $unsubscribeUrl;
        }

        $textBody = $rendered->isHtml() ? \Espo\Tools\Email\Util::stripHtml($body) : $body;
        $htmlBody = $rendered->isHtml() ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $preheader = trim((string) ($profile['preheader'] ?? ''));
        if ($preheader !== '') {
            $previewTemplate = $this->entityManager->getNewEntity(EmailTemplate::ENTITY_TYPE);
            $previewTemplate->set(['subject' => $preheader, 'body' => '', 'isHtml' => true]);
            $preview = $this->templateProcessor->process($previewTemplate, TemplateParams::create()->withApplyAcl($testUser !== null)->withCopyAttachments(false), TemplateData::create()->withParent($contact));
            $htmlBody = $this->withPreheader($htmlBody, $preview->getSubject());
        }

        if (!$testUser && !$this->reserveItem($context, $profile, $queueItemId, (string) $recipient['email'])) {
            return;
        }
        try {
            $result = $this->provider->send(new DeliveryRequest(
                recipient: (string) $recipient['email'],
                fromAddress: (string) $profile['from_address'],
                fromName: $profile['from_name'] ?: null,
                replyToAddress: $profile['reply_to_address'] ?: null,
                subject: ($testUser ? '[Test] ' : '') . $rendered->getSubject(),
                htmlBody: $htmlBody,
                textBody: $textBody,
                queueItemId: $queueItemId,
                headers: ['List-Unsubscribe' => '<' . $unsubscribeUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
                attachments: $files,
            ));
            $pdo = $this->entityManager->getPDO();
            $message = $pdo->prepare("UPDATE nexa_marketing_delivery_message SET provider_message_id=?,provider_token=?,status='submitted',attempt_count=attempt_count+1,submitted_at=NOW(6),modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND email_queue_item_id=? AND status='submitting'");
            $message->execute([$result->providerMessageId, $result->providerToken, $context->tenantId, $context->serviceId, $queueItemId]);
            $queue = $pdo->prepare("UPDATE email_queue_item SET status='Sent',email_address=?,attempt_count=attempt_count+1,sent_at=NOW() WHERE id=? AND tenant_id=? AND service_id=? AND status='Sending'");
            $queue->execute([$recipient['email'], $queueItemId, $context->tenantId, $context->serviceId]);
        } catch (\Throwable $e) {
            if ($e instanceof DeliveryProviderException && !$e->isSubmissionUncertain()) {
                $this->markDeliveryFailed($context, $queueItemId, $e->getMessage());
            } else {
                $this->holdUncertainSubmission($context, $profile, $queueItemId);
            }
        }
    }

    /** Keep the native queue and allowance reserved when acceptance cannot be disproved. */
    private function holdUncertainSubmission(TenantContext $context, array $profile, string $queueItemId): void
    {
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("UPDATE nexa_marketing_delivery_message SET last_error=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND profile_id=? AND email_queue_item_id=? AND status IN ('submitting','submitted')");
            $update->execute(['Postal acceptance is uncertain. Review provider delivery evidence before retrying.', $context->tenantId, $context->serviceId, $profile['id'], $queueItemId]);
            if ($update->rowCount() > 0) {
                $pause = $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='paused',modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status IN ('sending','scheduled','approved')");
                $pause->execute([$profile['id'], $context->tenantId, $context->serviceId]);
                if ($pause->rowCount() === 1) {
                    $pdo->prepare("UPDATE mass_email SET status='Nexa Paused',modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?")
                        ->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
                    $this->event($context, $profile['id'], 'submission_review_required', (int) $profile['version_number'], ['message' => 'Postal acceptance is uncertain. No automatic retry or allowance release was performed.']);
                }
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $profile */
    private function reserveItem(TenantContext $context, array $profile, string $queueItemId, string $email, bool $isTest = false): bool
    {
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $usage = $pdo->prepare("INSERT IGNORE INTO nexa_marketing_monthly_usage (tenant_id,service_id,usage_month) VALUES (?,?,DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01'))");
            $usage->execute([$context->tenantId, $context->serviceId]);
            $capacity = $pdo->prepare("SELECT p.monthly_send_limit,u.accepted_count FROM nexa_marketing_delivery_provider p INNER JOIN nexa_marketing_monthly_usage u ON u.tenant_id=p.tenant_id AND u.service_id=p.service_id AND u.usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') WHERE p.tenant_id=? AND p.service_id=? FOR UPDATE");
            $capacity->execute([$context->tenantId, $context->serviceId]);
            $usageRow = $capacity->fetch(PDO::FETCH_ASSOC);
            $locked = $this->profile($context, (string) $profile['id'], true);
            if ($locked['lifecycle_status'] !== ($isTest ? 'approved' : 'sending')) {
                if ($ownsTransaction) $pdo->rollBack();
                return false;
            }
            $limit = MarketingAllowance::limit($pdo, $context, (int) ($usageRow['monthly_send_limit'] ?? 0));
            if (!$usageRow || (int) $usageRow['accepted_count'] >= $limit) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }
            $queue = $pdo->prepare("UPDATE email_queue_item SET status='Sending' WHERE id=? AND tenant_id=? AND service_id=? AND status='Pending' AND is_test=?");
            $queue->execute([$queueItemId, $context->tenantId, $context->serviceId, (int) $isTest]);
            if ($queue->rowCount() !== 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }
            $insert = $pdo->prepare("INSERT IGNORE INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,recipient,status) VALUES (?,?,?,?,?,?,?,'submitting')");
            $insert->execute([$this->uuid(), $context->tenantId, $context->serviceId, $profile['id'], $queueItemId, 'postal', $email]);
            if ($insert->rowCount() !== 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }
            $reserve = $pdo->prepare("UPDATE nexa_marketing_monthly_usage SET accepted_count=accepted_count+1 WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')");
            $reserve->execute([$context->tenantId, $context->serviceId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $profile @return array<int, array{id:string,email:string}> */
    private function eligibleRecipients(TenantContext $context, array $profile): array
    {
        $statement = $this->entityManager->getPDO()->prepare($this->eligibilitySql() . ' ORDER BY c.id');
        $statement->execute([$profile['purpose_id'], $context->tenantId, $context->serviceId, $profile['campaign_id']]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $profile @return array{id:string,email:string}|null */
    private function eligibleRecipient(TenantContext $context, array $profile, string $contactId): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare($this->eligibilitySql() . ' AND c.id=? LIMIT 1');
        $statement->execute([$profile['purpose_id'], $context->tenantId, $context->serviceId, $profile['campaign_id'], $contactId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function eligibilitySql(): string
    {
        return "SELECT c.id,ea.name AS email FROM nexa_campaign_enrollment ce INNER JOIN contact c ON c.id=ce.contact_id AND c.tenant_id=ce.tenant_id AND c.service_id=ce.service_id AND c.deleted=0 LEFT JOIN entity_email_address eea ON eea.entity_id=c.id AND eea.entity_type='Contact' AND eea.`primary`=1 AND eea.deleted=0 AND eea.tenant_id=c.tenant_id AND eea.service_id=c.service_id LEFT JOIN email_address ea ON ea.id=eea.email_address_id AND ea.tenant_id=c.tenant_id AND ea.service_id=c.service_id LEFT JOIN nexa_consent_state cs ON cs.tenant_id=c.tenant_id AND cs.service_id=c.service_id AND cs.contact_id=c.id AND cs.purpose_id=? AND cs.channel='email' LEFT JOIN nexa_marketing_suppression ms ON ms.tenant_id=c.tenant_id AND ms.service_id=c.service_id AND ms.email_address=LOWER(ea.name) AND ms.is_active=1 WHERE ce.tenant_id=? AND ce.service_id=? AND ce.campaign_id=? AND ce.status='enrolled' AND c.marketing_status='Marketing' AND c.do_not_contact=0 AND (c.do_not_contact_channels IS NULL OR FIND_IN_SET('email',REPLACE(LOWER(c.do_not_contact_channels),' ',''))=0) AND ea.name IS NOT NULL AND ea.invalid=0 AND ea.opt_out=0 AND cs.status='granted' AND (cs.expires_at IS NULL OR cs.expires_at>NOW()) AND ms.id IS NULL AND NOT EXISTS (SELECT 1 FROM campaign_target_list ctlists INNER JOIN contact_target_list ctl ON ctl.target_list_id=ctlists.target_list_id AND ctl.tenant_id=ctlists.tenant_id AND ctl.service_id=ctlists.service_id AND ctl.contact_id=c.id AND ctl.deleted=0 AND ctl.opted_out=1 WHERE ctlists.campaign_id=ce.campaign_id AND ctlists.tenant_id=ce.tenant_id AND ctlists.service_id=ce.service_id AND ctlists.deleted=0)";
    }

    /** @param array<string, mixed> $profile */
    private function eligibleCount(TenantContext $context, array $profile): int
    {
        return count($this->eligibleRecipients($context, $profile));
    }

    private function requireVerifiedSender(TenantContext $context, string $address): void
    {
        $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
        $statement = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM nexa_marketing_sending_domain WHERE tenant_id=? AND service_id=? AND domain_name=? AND ownership_status='verified' AND spf_status='verified' AND dkim_status='verified' AND dmarc_status='verified' AND return_path_status='verified'");
        $statement->execute([$context->tenantId, $context->serviceId, $domain]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new BadRequest('The sender email must use a verified sending domain.');
        }
    }

    private function requireCapacity(TenantContext $context, int $requested): void
    {
        $remaining = $this->remainingCapacity($context);
        if ($remaining !== null && $requested > $remaining) {
            throw new BadRequest("This send exceeds the workspace monthly limit. {$remaining} sends remain.");
        }
    }

    private function remainingCapacity(TenantContext $context): ?int
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.monthly_send_limit,COALESCE(u.accepted_count,0) accepted_count FROM nexa_marketing_delivery_provider p LEFT JOIN nexa_marketing_monthly_usage u ON u.tenant_id=p.tenant_id AND u.service_id=p.service_id AND u.usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') WHERE p.tenant_id=? AND p.service_id=? LIMIT 1");
        $statement->execute([$context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $limit = MarketingAllowance::limit($this->entityManager->getPDO(), $context, (int) $row['monthly_send_limit']);
        return max(0, $limit - (int) $row['accepted_count']);
    }

    /** @return array<string, mixed> */
    private function profile(TenantContext $context, string $id, bool $lock): array
    {
        $sql = "SELECT p.*,m.email_template_id,m.campaign_id,m.start_at,m.from_address,m.from_name,m.reply_to_address,cp.workflow_status campaign_status FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id INNER JOIN nexa_campaign_profile cp ON cp.campaign_id=m.campaign_id AND cp.tenant_id=p.tenant_id AND cp.service_id=p.service_id WHERE p.id=? AND p.tenant_id=? AND p.service_id=? AND m.deleted=0 LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $statement = $this->entityManager->getPDO()->prepare($sql);
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFound('Marketing email not found.');
        }
        return $row;
    }

    /** @param array<string, mixed> $profile */
    private function completeProfile(TenantContext $context, array $profile): void
    {
        $pdo = $this->entityManager->getPDO();
        $update = $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='completed',modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status='sending'");
        $update->execute([$profile['id'], $context->tenantId, $context->serviceId]);
        if ($update->rowCount() !== 1) return;
        $native = $pdo->prepare("UPDATE mass_email SET status='Complete',modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?");
        $native->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
        $this->event($context, (string) $profile['id'], 'submitted', (int) $profile['version_number'], []);
    }

    /** @param array<string, mixed> $profile */
    private function failProfile(TenantContext $context, array $profile, string $message): void
    {
        $pdo = $this->entityManager->getPDO();
        $update = $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='failed',modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status IN ('scheduled','sending')");
        $update->execute([$profile['id'], $context->tenantId, $context->serviceId]);
        if ($update->rowCount() !== 1) return;
        $native = $pdo->prepare("UPDATE mass_email SET status='Failed',modified_at=NOW() WHERE id=? AND tenant_id=? AND service_id=?");
        $native->execute([$profile['mass_email_id'], $context->tenantId, $context->serviceId]);
        $this->event($context, (string) $profile['id'], 'failed', (int) $profile['version_number'], ['message' => mb_substr($message, 0, 1000)]);
    }

    private function markQueueFailed(TenantContext $context, string $queueItemId, string $message): void
    {
        $statement = $this->entityManager->getPDO()->prepare("UPDATE email_queue_item SET status='Failed',attempt_count=attempt_count+1 WHERE id=? AND tenant_id=? AND service_id=?");
        $statement->execute([$queueItemId, $context->tenantId, $context->serviceId]);
    }

    private function markDeliveryFailed(TenantContext $context, string $queueItemId, string $message): void
    {
        $pdo = $this->entityManager->getPDO();
        $delivery = $pdo->prepare("UPDATE nexa_marketing_delivery_message SET status='failed',attempt_count=attempt_count+1,last_error=?,reconciled_at=NOW(6),modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND email_queue_item_id=?");
        $delivery->execute([mb_substr($message, 0, 1000), $context->tenantId, $context->serviceId, $queueItemId]);
        $usage = $pdo->prepare("UPDATE nexa_marketing_monthly_usage SET accepted_count=GREATEST(accepted_count-1,0) WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')");
        $usage->execute([$context->tenantId, $context->serviceId]);
        $this->markQueueFailed($context, $queueItemId, $message);
    }

    /** @param array<string, mixed> $payload */
    private function event(TenantContext $context, string $profileId, string $type, int $version, array $payload): void
    {
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_marketing_email_event (id,tenant_id,service_id,profile_id,event_type,version_number,payload_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $profileId, $type, $version, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->user->getId()]);
    }

    private function withPreheader(string $html, string $text): string
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction) $document->removeChild($node);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body) throw new DeliveryProviderException('The email HTML body could not be prepared.');
        $preview = $document->createElement('div');
        $preview->setAttribute('data-nexa-preheader', 'true');
        $preview->setAttribute('aria-hidden', 'true');
        $preview->setAttribute('style', 'display:none!important;max-height:0;max-width:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;mso-hide:all;');
        $preview->appendChild($document->createTextNode($text));
        $body->insertBefore($preview, $body->firstChild);
        return $document->saveHTML();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
