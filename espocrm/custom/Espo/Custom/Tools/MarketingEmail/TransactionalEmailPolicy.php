<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use PDO;

final class TransactionalEmailPolicy
{
    public function __construct(private EntityManager $entityManager) {}

    public function requirePurpose(TenantContext $context, string $id): void
    {
        $query = $this->entityManager->getPDO()->prepare(
            "SELECT default_legal_basis,channels_json FROM nexa_consent_purpose WHERE id=? AND tenant_id=? AND service_id=? " .
            "AND purpose_key='customer_service' AND is_system=1 AND is_active=1"
        );
        $query->execute([$id, $context->tenantId, $context->serviceId]);
        $purpose = $query->fetch(PDO::FETCH_ASSOC);
        if (!$purpose || !in_array('email', json_decode($purpose['channels_json'], true) ?: [], true)
            || !in_array($purpose['default_legal_basis'], ['PerformanceOfContract', 'FreelyGivenConsent'], true)) {
            throw new BadRequest('Transactional email requires the active Customer service email purpose.');
        }
    }

    public function recipient(TenantContext $context, string $purposeId, string $contactId): ?array
    {
        $this->requirePurpose($context, $purposeId);
        $query = $this->entityManager->getPDO()->prepare(
            "SELECT c.id,ea.name AS email FROM contact c " .
            "INNER JOIN entity_email_address eea ON eea.entity_id=c.id AND eea.entity_type='Contact' AND eea.`primary`=1 " .
            "AND eea.deleted=0 AND eea.tenant_id=c.tenant_id AND eea.service_id=c.service_id " .
            "INNER JOIN email_address ea ON ea.id=eea.email_address_id AND ea.tenant_id=c.tenant_id AND ea.service_id=c.service_id " .
            "INNER JOIN nexa_consent_state cs ON cs.contact_id=c.id AND cs.tenant_id=c.tenant_id AND cs.service_id=c.service_id AND cs.channel='email' " .
            "INNER JOIN nexa_consent_purpose p ON p.id=cs.purpose_id AND p.tenant_id=cs.tenant_id AND p.service_id=cs.service_id " .
            "INNER JOIN nexa_consent_event e ON e.id=cs.source_event_id AND e.tenant_id=cs.tenant_id AND e.service_id=cs.service_id " .
            "AND e.contact_id=c.id AND e.purpose_id=p.id AND e.channel='email' AND e.status=cs.status AND e.legal_basis=cs.legal_basis " .
            "WHERE c.id=? AND c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND p.id=? " .
            "AND c.do_not_contact=0 AND (c.do_not_contact_channels IS NULL OR FIND_IN_SET('email',REPLACE(LOWER(c.do_not_contact_channels),' ',''))=0) " .
            "AND ea.invalid=0 AND ea.opt_out=0 AND ea.name IS NOT NULL " .
            "AND cs.effective_at<=NOW(6) AND (cs.expires_at IS NULL OR cs.expires_at>NOW(6)) " .
            "AND cs.policy_version=p.policy_version AND e.voided_at IS NULL AND e.superseded_by_event_id IS NULL " .
            "AND ((cs.status='granted' AND cs.legal_basis='FreelyGivenConsent') " .
            "OR (cs.status='not_required' AND cs.legal_basis='PerformanceOfContract' AND TRIM(COALESCE(e.evidence_note,''))<>'')) " .
            "AND NOT EXISTS (SELECT 1 FROM nexa_marketing_suppression ms WHERE ms.tenant_id=c.tenant_id AND ms.service_id=c.service_id " .
            "AND ms.email_address=LOWER(ea.name) AND ms.is_active=1) LIMIT 1"
        );
        $query->execute([$contactId, $context->tenantId, $context->serviceId, $purposeId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
