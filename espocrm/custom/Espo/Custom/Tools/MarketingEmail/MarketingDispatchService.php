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
    ) {}

    /** @return array{id:string,status:string,eligibleCount:int,scheduledAt:string} */
    public function schedule(string $profileId): array
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Only a tenant administrator can schedule marketing email.');
        }
        $context = $this->tenantContextStore->require();
        $profile = $this->profile($context, $profileId, true);
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
                $queue = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND deleted=0');
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

        $statement = $this->entityManager->getPDO()->prepare("SELECT q.id,q.target_id FROM email_queue_item q LEFT JOIN nexa_marketing_delivery_message d ON d.tenant_id=q.tenant_id AND d.service_id=q.service_id AND d.email_queue_item_id=q.id WHERE q.tenant_id=? AND q.service_id=? AND q.mass_email_id=? AND q.status='Pending' AND q.deleted=0 AND d.id IS NULL ORDER BY q.created_at,q.id LIMIT " . self::BATCH_SIZE);
        $statement->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->dispatchItem($context, $profile, $massEmail, $template, (string) $row['id'], (string) $row['target_id']);
        }
        $remaining = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND status IN ('Pending','Sending') AND deleted=0");
        $remaining->execute([$context->tenantId, $context->serviceId, $profile['mass_email_id']]);
        if ((int) $remaining->fetchColumn() === 0) {
            $failed = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND status='Failed' AND deleted=0");
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
                WHERE p.tenant_id=? AND p.service_id=? AND p.lifecycle_status='sending'
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
    private function dispatchItem(TenantContext $context, array $profile, MassEmail $massEmail, EmailTemplate $template, string $queueItemId, string $contactId): void
    {
        $recipient = $this->eligibleRecipient($context, $profile, $contactId);
        if ($recipient === null) {
            $this->markQueueFailed($context, $queueItemId, 'Recipient became ineligible before delivery.');
            return;
        }
        $contact = $this->entityManager->getEntityById('Contact', $contactId);
        if (!$contact instanceof Entity) {
            $this->markQueueFailed($context, $queueItemId, 'Contact is unavailable.');
            return;
        }
        $rendered = $this->templateProcessor->process($template, TemplateParams::create()->withApplyAcl(false)->withCopyAttachments(false), TemplateData::create()->withParent($contact));
        $unsubscribeUrl = rtrim($this->applicationConfig->getSiteUrl(), '/') . '/api/v1/Nexa/public/marketing-unsubscribe/' . rawurlencode($queueItemId) . '/' . $this->unsubscribe->token($queueItemId);
        $body = str_replace(['{queueItemId}', '{optOutUrl}', '{optOutLink}'], [$queueItemId, $unsubscribeUrl, '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Unsubscribe</a>'], $rendered->getBody());
        if (!str_contains($body, '/Nexa/public/marketing-unsubscribe/')) {
            $body .= $rendered->isHtml() ? '<br><br><a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Unsubscribe</a>' : "\n\n" . $unsubscribeUrl;
        }

        if (!$this->reserveItem($context, $profile, $queueItemId, (string) $recipient['email'])) {
            return;
        }
        try {
            $result = $this->provider->send(new DeliveryRequest(
                recipient: (string) $recipient['email'],
                fromAddress: (string) $profile['from_address'],
                fromName: $profile['from_name'] ?: null,
                replyToAddress: $profile['reply_to_address'] ?: null,
                subject: $rendered->getSubject(),
                htmlBody: $body,
                textBody: null,
                queueItemId: $queueItemId,
                headers: ['List-Unsubscribe' => '<' . $unsubscribeUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
            ));
            $pdo = $this->entityManager->getPDO();
            $message = $pdo->prepare("UPDATE nexa_marketing_delivery_message SET provider_message_id=?,provider_token=?,status='submitted',attempt_count=attempt_count+1,submitted_at=NOW(6),modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND email_queue_item_id=? AND status='submitting'");
            $message->execute([$result->providerMessageId, $result->providerToken, $context->tenantId, $context->serviceId, $queueItemId]);
            $queue = $pdo->prepare("UPDATE email_queue_item SET status='Sent',email_address=?,attempt_count=attempt_count+1,sent_at=NOW() WHERE id=? AND tenant_id=? AND service_id=? AND status='Sending'");
            $queue->execute([$recipient['email'], $queueItemId, $context->tenantId, $context->serviceId]);
        } catch (\Throwable $e) {
            $this->markDeliveryFailed($context, $queueItemId, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $profile */
    private function reserveItem(TenantContext $context, array $profile, string $queueItemId, string $email): bool
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
            if ($locked['lifecycle_status'] !== 'sending') {
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
            $queue = $pdo->prepare("UPDATE email_queue_item SET status='Sending' WHERE id=? AND tenant_id=? AND service_id=? AND status='Pending'");
            $queue->execute([$queueItemId, $context->tenantId, $context->serviceId]);
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
