<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\EmailTemplate;
use Espo\Modules\Crm\Entities\MassEmail;
use PDO;

/** Verify persisted and in-memory native content against the immutable governed version. */
final class MarketingContentIntegrity
{
    public function __construct(private EntityManager $entityManager, private TenantContextStore $contexts) {}

    public function requireSnapshot(array $profile, EmailTemplate $template, ?MassEmail $massEmail = null): void
    {
        $context = $this->contexts->require();
        $query = $this->entityManager->getPDO()->prepare('SELECT v.subject,v.preheader,v.body_html,v.body_text,v.content_hash,v.delivery_manifest_json,t.subject AS native_subject,t.body AS native_body,p.preheader AS native_preheader,p.purpose_id,p.delivery_mode,m.id AS mass_email_id,m.from_address,m.from_name,m.reply_to_address,m.reply_to_name,m.campaign_id FROM nexa_marketing_email_version v INNER JOIN nexa_marketing_email_profile p ON p.id=v.profile_id AND p.tenant_id=v.tenant_id AND p.service_id=v.service_id AND p.version_number=v.version_number INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id AND m.email_template_id=v.email_template_id AND m.deleted=0 INNER JOIN email_template t ON t.id=v.email_template_id AND t.tenant_id=v.tenant_id AND t.service_id=v.service_id AND t.deleted=0 WHERE v.profile_id=? AND v.version_number=? AND v.email_template_id=? AND v.tenant_id=? AND v.service_id=?');
        $query->execute([$profile['id'], $profile['version_number'], $template->getId(), $context->tenantId, $context->serviceId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $template->get('tenantId') !== $context->tenantId || $template->get('serviceId') !== $context->serviceId) throw new BadRequest('The saved email content version is unavailable.');
        $manifest = json_decode((string) ($row['delivery_manifest_json'] ?? ''), true);
        if (!is_array($manifest) || !is_array($manifest['policy'] ?? null) || !is_string($manifest['sha256'] ?? null)) throw new BadRequest('This version has no delivery-policy approval evidence. Save and approve the email again.');
        $policy = [];
        foreach (['fromAddress'=>'from_address','fromName'=>'from_name','replyToAddress'=>'reply_to_address','replyToName'=>'reply_to_name','campaignId'=>'campaign_id','purposeId'=>'purpose_id','deliveryMode'=>'delivery_mode'] as $field=>$column) $policy[$field] = (string) ($row[$column] ?? '');
        ksort($policy, SORT_STRING); ksort($manifest['policy'], SORT_STRING);
        $policyJson = json_encode($manifest['policy'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($manifest['policy'] !== $policy || !hash_equals($manifest['sha256'],hash('sha256',$policyJson))) throw new BadRequest('Delivery settings changed outside their saved version. Save and approve the email again.');
        $native = $massEmail ?? $this->entityManager->getEntityById('MassEmail',$row['mass_email_id']);
        if (!$native || $native->getId() !== $row['mass_email_id'] || $native->get('tenantId') !== $context->tenantId || $native->get('serviceId') !== $context->serviceId) throw new BadRequest('Native delivery settings are unavailable.');
        foreach (['fromAddress','fromName','replyToAddress','replyToName','campaignId'] as $field) {
            if (!$native || (string) ($native->get($field) ?? '') !== $policy[$field]) throw new BadRequest('Native delivery settings no longer match the saved version.');
        }
        foreach (['purposeId'=>'purpose_id','deliveryMode'=>'delivery_mode'] as $field=>$column) {
            if ((string) ($profile[$column] ?? '') !== $policy[$field]) throw new BadRequest('The delivery profile no longer matches the saved version.');
        }
        foreach (['fromAddress'=>'from_address','fromName'=>'from_name','replyToAddress'=>'reply_to_address','replyToName'=>'reply_to_name','campaignId'=>'campaign_id'] as $field=>$column) {
            if (array_key_exists($column,$profile) && (string) ($profile[$column] ?? '') !== $policy[$field]) throw new BadRequest('Cached delivery settings no longer match the saved version.');
        }
        $hash = hash('sha256', $row['subject'] . "\0" . ($row['preheader'] ?? '') . "\0" . $row['body_html'] . "\0" . ($row['body_text'] ?? ''));
        if (!hash_equals($row['content_hash'], $hash) ||
            $row['native_subject'] !== $row['subject'] || $row['native_body'] !== $row['body_html'] ||
            ($row['native_preheader'] ?? '') !== ($row['preheader'] ?? '') ||
            $template->get('subject') !== $row['subject'] || $template->get('body') !== $row['body_html'] ||
            ($profile['preheader'] ?? '') !== ($row['preheader'] ?? '')) {
            throw new BadRequest('Email content changed outside its saved version. Save and approve it again before delivery.');
        }
    }
}
