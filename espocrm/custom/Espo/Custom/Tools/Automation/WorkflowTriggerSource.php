<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use PDO;

/** Read committed native events without consuming another module's outbox. */
final class WorkflowTriggerSource
{
    public const EVENTS = ['contact.created','contact.updated','contact.field_changed','lifecycle.changed','score.reached','segment.entered','segment.exited','email.opened','email.clicked','opportunity.stage_changed','activity.completed','date.reached'];

    public function __construct(private EntityManager $em, private TenantContextStore $contexts) {}

    public function events(array $trigger, string $publishedAt, string $workflowId): array
    {
        $context=$this->contexts->require();
        $scope=[$context->tenantId,$context->serviceId];
        $event=$trigger['event'];
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

    private function dates(array $trigger,string $publishedAt,string $workflowId): array
    {
        $context=$this->contexts->require();$scope=[$context->tenantId,$context->serviceId];
        $identity="MD5(CONCAT('date:',v.entity_id,':',d.id,':',COALESCE(CAST(v.value_datetime AS CHAR),CAST(v.value_date AS CHAR))))";
        $sql="SELECT $identity AS id,v.entity_id AS contact_id FROM nexa_custom_field_value v INNER JOIN nexa_custom_field_definition d ON d.id=v.field_definition_id AND d.tenant_id=v.tenant_id AND d.service_id=v.service_id AND d.entity_type='Contact' AND d.is_active=1 INNER JOIN contact c ON c.id=v.entity_id AND c.tenant_id=v.tenant_id AND c.service_id=v.service_id AND c.deleted=0 WHERE v.tenant_id=? AND v.service_id=? AND v.entity_type='Contact' AND d.field_key=? AND ((d.data_type='date' AND v.value_date BETWEEN DATE(?) AND UTC_DATE()) OR (d.data_type='datetime' AND v.value_datetime BETWEEN ? AND UTC_TIMESTAMP(6))) AND NOT EXISTS (SELECT 1 FROM nexa_workflow_trigger_receipt r WHERE r.tenant_id=v.tenant_id AND r.service_id=v.service_id AND r.workflow_id=? AND r.event_id=$identity) ORDER BY COALESCE(v.value_datetime,v.value_date),v.entity_id LIMIT 25";
        $query=$this->em->getPDO()->prepare($sql);$query->execute([...$scope,substr($trigger['field'],7),$publishedAt,$publishedAt,$workflowId]);return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
