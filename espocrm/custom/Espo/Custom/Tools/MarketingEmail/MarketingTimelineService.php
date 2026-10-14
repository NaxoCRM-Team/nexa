<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use PDO;

final class MarketingTimelineService
{
    public function __construct(private EntityManager $entityManager, private TenantContextStore $tenantContextStore) {}

    public function project(string $deliveryEventId): void
    {
        $context = $this->tenantContextStore->require();
        $pdo = $this->entityManager->getPDO();
        $find = $pdo->prepare(
            "SELECT e.id,e.event_type,e.occurred_at,d.profile_id,c.id AS contact_id,a.id AS account_id " .
            "FROM nexa_marketing_delivery_event e " .
            "INNER JOIN nexa_marketing_delivery_message d ON d.tenant_id=e.tenant_id AND d.service_id=e.service_id " .
            "AND d.profile_id=e.profile_id AND d.recipient=e.recipient " .
            "AND d.provider_message_id=e.provider_message_id " .
            "INNER JOIN email_queue_item q ON q.id=d.email_queue_item_id AND q.tenant_id=d.tenant_id " .
            "AND q.service_id=d.service_id AND q.target_type='Contact' " .
            "INNER JOIN contact c ON c.id=q.target_id AND c.tenant_id=q.tenant_id AND c.service_id=q.service_id AND c.deleted=0 " .
            "LEFT JOIN account a ON a.id=c.account_id AND a.tenant_id=c.tenant_id AND a.service_id=c.service_id AND a.deleted=0 " .
            "WHERE e.id=? AND e.tenant_id=? AND e.service_id=? AND e.event_type<>'unknown' LIMIT 1"
        );
        $find->execute([$deliveryEventId, $context->tenantId, $context->serviceId]);
        $event = $find->fetch(PDO::FETCH_ASSOC);
        if (!$event) return;

        $type = 'email.' . $event['event_type'];
        $metadata = json_encode(['eventCategory' => 'email', 'profileId' => $event['profile_id'], 'provider' => 'postal'], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare(
            "INSERT IGNORE INTO nexa_timeline_event " .
            "(id,tenant_id,service_id,contact_id,account_id,event_type,source_entity_type,source_entity_id," .
            "source_occurred_at,actor_type,visibility,summary,metadata_json) " .
            "VALUES (UUID(),?,?,?,?,?,'MarketingDeliveryEvent',?,?,'system','internal',?,?)"
        );
        $insert->execute([$context->tenantId, $context->serviceId, $event['contact_id'], $event['account_id'],
            $type, $event['id'], $event['occurred_at'], 'Marketing email ' . $event['event_type'], $metadata]);
        if ($insert->rowCount() === 0) return;

        $pdo->prepare(
            "INSERT INTO nexa_outbox_event (id,tenant_id,service_id,event_type,aggregate_type,aggregate_id,payload_json,occurred_at) " .
            "VALUES (UUID(),?,?,?,'MarketingDeliveryEvent',?,?,?)"
        )->execute([$context->tenantId, $context->serviceId, $type, $event['id'], json_encode([
            'deliveryEventId' => $event['id'], 'contactId' => $event['contact_id'],
            'accountId' => $event['account_id'], 'profileId' => $event['profile_id'],
        ], JSON_THROW_ON_ERROR), $event['occurred_at']]);
    }
}
