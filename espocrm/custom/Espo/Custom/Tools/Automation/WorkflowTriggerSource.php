<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use PDO;

/** Read committed native events without consuming another module's outbox. */
final class WorkflowTriggerSource
{
    public const EVENTS = ['contact.created','contact.updated','contact.field_changed','lifecycle.changed','score.reached','segment.entered','segment.exited','email.opened','email.clicked','opportunity.stage_changed','activity.completed','date.reached','conversation.received'];

    public function __construct(private EntityManager $em, private TenantContextStore $contexts) {}

    public function events(array $trigger, string $publishedAt, string $workflowId): array
    {
        $context=$this->contexts->require();
        $scope=[$context->tenantId,$context->serviceId];
        $event=$trigger['event'];
        if($event==='conversation.received')return $this->conversations($publishedAt,$workflowId);
        if($event==='date.reached')return $this->dates($trigger,$publishedAt,$workflowId);
        $params=[...$scope];
        if (in_array($event,['segment.entered','segment.exited'],true)) {
            $sql="SELECT s.id,s.contact_id FROM nexa_segment_membership_event s WHERE s.tenant_id=? AND s.service_id=? AND s.target_list_id=? AND s.event_type=? AND s.occurred_at>=? AND s.occurred_at<=UTC_TIMESTAMP(6)";
            array_push($params,$trigger['segmentId'],$event==='segment.entered'?'entered':'exited',$publishedAt);
            $source='s';$time='occurred_at';
        } elseif (in_array($event,['email.opened','email.clicked'],true)) {
            $sql="SELECT DISTINCT t.source_entity_id AS id,t.contact_id FROM nexa_timeline_event t INNER JOIN nexa_marketing_delivery_event d ON d.id=t.source_entity_id AND d.tenant_id=t.tenant_id AND d.service_id=t.service_id AND CONCAT('email.',d.event_type)=t.event_type WHERE t.tenant_id=? AND t.service_id=? AND t.event_type=? AND t.source_entity_type='MarketingDeliveryEvent' AND t.contact_id IS NOT NULL AND t.visibility IN ('internal','team','customer') AND t.source_occurred_at>=? AND t.source_occurred_at<=UTC_TIMESTAMP(6)";
            array_push($params,$event,$publishedAt);
            $source='t';$time='source_occurred_at';
        } else {
            $type=match($event){'lifecycle.changed'=>'contact.lifecycle.changed','contact.field_changed','score.reached'=>'contact.updated',default=>$event};
            $sql="SELECT e.id,e.aggregate_id AS contact_id,JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.metadata.sourceType')) AS source_type,JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.metadata.sourceId')) AS source_id FROM nexa_outbox_event e INNER JOIN contact c ON c.id=e.aggregate_id AND c.tenant_id=e.tenant_id AND c.service_id=e.service_id AND c.deleted=0 WHERE e.tenant_id=? AND e.service_id=? AND e.aggregate_type='Contact' AND e.event_type=? AND e.payload_version=1 AND e.occurred_at>=? AND e.occurred_at<=UTC_TIMESTAMP(6)";
            array_push($params,$type,$publishedAt);
            if ($event==='contact.field_changed' || $event==='score.reached') {
                $sql.=" AND JSON_CONTAINS(JSON_EXTRACT(e.payload_json,'$.metadata.changedFields'),JSON_QUOTE(?))=1";
                $params[]=$event==='score.reached'?'leadScore':$trigger['field'];
            }
            if ($event==='score.reached') {
                $sql.=" AND JSON_TYPE(JSON_EXTRACT(e.payload_json,'$.metadata.score'))='INTEGER' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.metadata.score')) AS SIGNED)>=? AND (JSON_TYPE(JSON_EXTRACT(e.payload_json,'$.metadata.previousScore'))='NULL' OR CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.metadata.previousScore')) AS SIGNED)<?)";
                array_push($params,$trigger['threshold'],$trigger['threshold']);
            }
            $source='e';$time='occurred_at';
        }
        $identity=$source==='t'?'t.source_entity_id':$source.'.id';
        $sql.=" AND NOT EXISTS (SELECT 1 FROM nexa_workflow_trigger_receipt r WHERE r.tenant_id=$source.tenant_id AND r.service_id=$source.service_id AND r.workflow_id=? AND r.event_id=$identity)";
        $params[]=$workflowId;
        // Apply eligibility before limiting; stale or unrelated events cannot starve eligible ones.
        $sql.=$source==='t'?" ORDER BY t.source_entity_id,t.contact_id LIMIT 25":" ORDER BY $source.$time,$source.id LIMIT 25";
        $query=$this->em->getPDO()->prepare($sql);$query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function conversations(string $publishedAt,string $workflowId): array
    {
        $ctx=$this->contexts->require();$scope=[$ctx->tenantId,$ctx->serviceId];
        // Read verified, committed channel receipts; never consume or copy message bodies.
        $parts=[];$params=[];
        foreach([
            ['nexa_messaging_receipt','received_at',"r.kind='inbound' AND p.provider='twilio' AND p.channel IN ('sms','whatsapp')",'twilio'],
            ['nexa_messenger_event','provider_at',"p.provider='meta' AND p.channel='messenger'",'messenger'],
        ] as [$table,$time,$filter,$kind]){
            $parts[]="SELECT MD5(CONCAT('$kind:',r.id)) AS id,c.contact_id,c.id AS source_id,'NexaMessagingConversation' AS source_type,r.$time AS occurred_at FROM $table r JOIN nexa_messaging_conversation c ON c.id=r.conversation_id AND c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.connection_id=r.connection_id JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id JOIN contact k ON k.id=c.contact_id AND k.tenant_id=c.tenant_id AND k.service_id=c.service_id AND k.deleted=0 WHERE r.tenant_id=? AND r.service_id=? AND c.deleted=0 AND $filter AND r.$time>=? AND r.$time<=UTC_TIMESTAMP(6)";
            $params=array_merge($params,[...$scope,$publishedAt]);
        }
        $parts[]="SELECT MD5(CONCAT('chat:',m.id)) AS id,v.contact_id,c.id AS source_id,'NexaChatConversation' AS source_type,m.created_at AS occurred_at FROM nexa_chat_message m JOIN nexa_chat_conversation c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id JOIN nexa_visitor_identity v ON v.id=c.visitor_identity_id AND v.tenant_id=c.tenant_id AND v.service_id=c.service_id AND v.visitor_key_hash=c.visitor_key_hash JOIN nexa_personalization_identity_proof p ON p.visitor_identity_id=v.id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.contact_id=v.contact_id AND p.issuer_user_id=c.identity_issuer_id JOIN user u ON u.id=p.issuer_user_id AND u.tenant_id=c.tenant_id AND u.service_id=c.service_id AND u.is_active=1 AND u.deleted=0 JOIN contact k ON k.id=v.contact_id AND k.tenant_id=c.tenant_id AND k.service_id=c.service_id AND k.deleted=0 WHERE m.tenant_id=? AND m.service_id=? AND c.deleted=0 AND m.author_kind='visitor' AND p.revoked_at IS NULL AND p.expires_at>UTC_TIMESTAMP(6) AND m.created_at>=? AND m.created_at<=UTC_TIMESTAMP(6)";
        $params=array_merge($params,[...$scope,$publishedAt,...$scope,$workflowId]);
        $sql='SELECT e.* FROM ('.implode(' UNION ALL ',$parts).') e WHERE NOT EXISTS (SELECT 1 FROM nexa_workflow_trigger_receipt r WHERE r.tenant_id=? AND r.service_id=? AND r.workflow_id=? AND r.event_id=e.id) ORDER BY e.occurred_at,e.id LIMIT 25';
        $query=$this->em->getPDO()->prepare($sql);$query->execute($params);return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dates(array $trigger,string $publishedAt,string $workflowId): array
    {
        $context=$this->contexts->require();$scope=[$context->tenantId,$context->serviceId];
        $identity="MD5(CONCAT('date:',v.entity_id,':',d.id,':',COALESCE(CAST(v.value_datetime AS CHAR),CAST(v.value_date AS CHAR))))";
        $sql="SELECT $identity AS id,v.entity_id AS contact_id FROM nexa_custom_field_value v INNER JOIN nexa_custom_field_definition d ON d.id=v.field_definition_id AND d.tenant_id=v.tenant_id AND d.service_id=v.service_id AND d.entity_type='Contact' AND d.is_active=1 INNER JOIN contact c ON c.id=v.entity_id AND c.tenant_id=v.tenant_id AND c.service_id=v.service_id AND c.deleted=0 WHERE v.tenant_id=? AND v.service_id=? AND v.entity_type='Contact' AND d.field_key=? AND ((d.data_type='date' AND v.value_date BETWEEN DATE(?) AND UTC_DATE()) OR (d.data_type='datetime' AND v.value_datetime BETWEEN ? AND UTC_TIMESTAMP(6))) AND NOT EXISTS (SELECT 1 FROM nexa_workflow_trigger_receipt r WHERE r.tenant_id=v.tenant_id AND r.service_id=v.service_id AND r.workflow_id=? AND r.event_id=$identity) ORDER BY COALESCE(v.value_datetime,v.value_date),v.entity_id LIMIT 25";
        $query=$this->em->getPDO()->prepare($sql);$query->execute([...$scope,substr($trigger['field'],7),$publishedAt,$publishedAt,$workflowId]);return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
