<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;
use Espo\Core\Exceptions\BadRequest;use Espo\Core\Exceptions\Forbidden;use Espo\Core\ORM\EntityManager;use Espo\Core\Tenant\TenantContext;use Espo\Core\Tenant\TenantContextStore;use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;use PDO;use stdClass;
/** Authenticated, idempotent Postal lifecycle reconciliation. */
final class PostalCallbackService
{
    public function __construct(private EntityManager $entityManager,private TenantContextStore $tenantContextStore,private PublicRequestLimiter $limiter,private MarketingTimelineService $timeline){}
    /** @return array{accepted:bool,duplicate:bool} */
    public function receive(string $token,stdClass $input):array
    {
        if(!preg_match('/^[a-f0-9]{48}$/',$token))throw new BadRequest('Callback token is invalid.');
        $provider=$this->provider($token);$encoded=json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($encoded)>262144)throw new BadRequest('Callback payload is too large.');
        $this->limiter->enforce($provider['tenant_id'],$provider['service_id'],'postal-callback:'.$provider['id'],600,60);
        $context=new TenantContext($provider['tenant_id'],'public','postal-delivery-callback','',$provider['service_id']);
        return $this->tenantContextStore->runWith($context,fn():array=>$this->reconcile($context,$input,$encoded));
    }
    /** @return array{accepted:bool,duplicate:bool} */
    private function reconcile(TenantContext $context,stdClass $input,string $encoded,?string $pendingId=null):array
    {
        $envelope=$input;
        if(is_object($input->payload??null))$input=$input->payload;
        $message=is_object($input->message??null)?$input->message:(is_object($input->original_message??null)?$input->original_message:new stdClass());
        $rawType=(string)($envelope->event??$input->event??$input->type??$input->status??'unknown');$type=$this->eventType($rawType,$input);$providerMessageId=trim((string)($message->message_id??$message->id??''));$providerToken=trim((string)($message->token??''));$recipient=mb_strtolower(trim((string)($message->to??$input->recipient??'')));$timestamp=(string)($input->timestamp??$message->timestamp??'');
        $eventId=hash('sha256',(string)($envelope->uuid??implode('|',[$rawType,$providerMessageId,$providerToken,$recipient,$timestamp,(string)($input->url??''),(string)($input->token??'')])));$occurred=$this->occurredAt($timestamp);$pdo=$this->entityManager->getPDO();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{$profileId=null;$matched=null;if($providerMessageId!==''||$providerToken!==''){$find=$pdo->prepare('SELECT id,profile_id,provider_message_id,recipient,usage_class,reserved_usage_month,status FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=? AND ((provider_message_id<>\'\' AND provider_message_id=?) OR (provider_token<>\'\' AND provider_token=?)) LIMIT 1 FOR UPDATE');$find->execute([$context->tenantId,$context->serviceId,$providerMessageId,$providerToken]);$matched=$find->fetch(PDO::FETCH_ASSOC);if($matched){if($recipient!==''&&strcasecmp($recipient,$matched['recipient'])!==0)throw new BadRequest('The callback recipient does not match the submitted message.');$profileId=$matched['profile_id'];$providerMessageId=(string)$matched['provider_message_id'];$recipient=mb_strtolower($matched['recipient']);}}
            $deliveryEventId=$this->uuid();
            if($pendingId!==null){
                $pending=$pdo->prepare('SELECT occurred_at FROM nexa_marketing_delivery_event WHERE id=? AND tenant_id=? AND service_id=? AND profile_id IS NULL FOR UPDATE');
                $pending->execute([$pendingId,$context->tenantId,$context->serviceId]);$originalTime=$pending->fetchColumn();
                if(!$originalTime||$profileId===null){if($ownsTransaction)$pdo->rollBack();return['accepted'=>true,'duplicate'=>true];}
                $occurred=(string)$originalTime;$deliveryEventId=$pendingId;
                $pdo->prepare('UPDATE nexa_marketing_delivery_event SET profile_id=?,provider_message_id=?,recipient=? WHERE id=? AND tenant_id=? AND service_id=?')->execute([$profileId,$providerMessageId,$recipient,$pendingId,$context->tenantId,$context->serviceId]);
            }else{
                $insert=$pdo->prepare('INSERT IGNORE INTO nexa_marketing_delivery_event (id,tenant_id,service_id,profile_id,provider_key,provider_event_id,provider_message_id,recipient,event_type,payload_json,occurred_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');$insert->execute([$deliveryEventId,$context->tenantId,$context->serviceId,$profileId,'postal',$eventId,$providerMessageId?:null,$recipient?:null,$type,$encoded,$occurred]);if($insert->rowCount()===0){if($ownsTransaction)$pdo->rollBack();return['accepted'=>true,'duplicate'=>true];}
            }
            $transitioned=false;
            if($matched&&!in_array($type,['opened','clicked','unknown'],true)){$status=$this->messageStatus($type);$update=$pdo->prepare("UPDATE nexa_marketing_delivery_message SET status=?,reconciled_at=NOW(6),modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND id=? AND status<>? AND (status NOT IN ('delivered','bounced','complained','failed') OR (status='delivered' AND ? IN ('bounced','complained')) OR (status='bounced' AND ?='complained'))");$update->execute([$status,$context->tenantId,$context->serviceId,$matched['id'],$status,$status,$status]);$transitioned=$update->rowCount()>0;}
            if($recipient!==''&&in_array($type,['bounced','complained'],true)){$reason=$type==='complained'?'complaint':'hard_bounce';$suppress=$pdo->prepare('INSERT INTO nexa_marketing_suppression (id,tenant_id,service_id,email_address,reason,source,provider_message_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),source=VALUES(source),provider_message_id=VALUES(provider_message_id),is_active=1,released_at=NULL,released_by_id=NULL');$suppress->execute([$this->uuid(),$context->tenantId,$context->serviceId,$recipient,$reason,'postal',$providerMessageId?:null]);}
            $column=$type==='delivered'?'delivered_count':(in_array($type,['bounced','failed','complained'],true)?'failed_count':null);
            if($column==='failed_count'&&in_array($matched['status']??null,['bounced','failed','complained'],true))$column=null;
            if($column&&$transitioned&&$matched){
                if($matched['usage_class']==='transactional')$column='transactional_'.$column;
                $month=$matched['reserved_usage_month']?:$occurred;
                $usage=$pdo->prepare("INSERT INTO nexa_marketing_monthly_usage (tenant_id,service_id,usage_month,{$column}) VALUES (?,?,DATE_FORMAT(?,'%Y-%m-01'),1) ON DUPLICATE KEY UPDATE {$column}={$column}+1");
                $usage->execute([$context->tenantId,$context->serviceId,$month]);
            }
            $this->timeline->project($deliveryEventId);
            if($ownsTransaction)$pdo->commit();return['accepted'=>true,'duplicate'=>false];}catch(\Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    /** Revisit authenticated receipts that arrived before the provider response was saved. */
    public function reconcilePending(): void
    {
        $context=$this->tenantContextStore->require();
        $query=$this->entityManager->getPDO()->prepare(
            "SELECT e.id,e.payload_json FROM nexa_marketing_delivery_event e " .
            "WHERE e.tenant_id=? AND e.service_id=? AND e.provider_key='postal' AND e.profile_id IS NULL AND e.event_type<>'unknown' " .
            "AND EXISTS (SELECT 1 FROM nexa_marketing_delivery_message d WHERE d.tenant_id=e.tenant_id AND d.service_id=e.service_id " .
            "AND (e.recipient IS NULL OR LOWER(e.recipient)=LOWER(d.recipient)) " .
            "AND ((d.provider_message_id<>'' AND d.provider_message_id=e.provider_message_id) OR (d.provider_token<>'' " .
            "AND d.provider_token=COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.payload.message.token'))," .
            "JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.payload.original_message.token'))," .
            "JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.message.token')),JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.original_message.token')))))) " .
            "ORDER BY e.received_at,e.id LIMIT 100"
        );
        $query->execute([$context->tenantId,$context->serviceId]);
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $event){
            $input=json_decode($event['payload_json'],false,512,JSON_THROW_ON_ERROR);
            if($input instanceof stdClass)$this->reconcile($context,$input,$event['payload_json'],$event['id']);
        }
    }
    /** @return array<string,string> */ private function provider(string $token):array{$statement=$this->entityManager->getPDO()->prepare("SELECT id,tenant_id,service_id FROM nexa_marketing_delivery_provider WHERE callback_token_hash=? AND status<>'disabled' LIMIT 1");$statement->execute([hash('sha256',$token)]);$row=$statement->fetch(PDO::FETCH_ASSOC);if(!$row)throw new Forbidden('Callback endpoint is unavailable.');return $row;}
    private function eventType(string $raw,stdClass $input):string{$value=strtolower($raw);if(str_contains($value,'bounce'))return'bounced';if(str_contains($value,'complaint')||str_contains($value,'spam'))return'complained';if(str_contains($value,'delay')||str_contains($value,'defer'))return'deferred';if(str_contains($value,'fail'))return'failed';if(str_contains($value,'sent')||strtolower((string)($input->status??''))==='sent')return'delivered';if(str_contains($value,'load')||str_contains($value,'open'))return'opened';if(str_contains($value,'click'))return'clicked';return'unknown';}
    private function messageStatus(string $type):string{return match($type){'delivered'=>'delivered','deferred'=>'deferred','bounced'=>'bounced','complained'=>'complained','failed'=>'failed',default=>'submitted'};}
    private function occurredAt(string $value):string{if(is_numeric($value))return gmdate('Y-m-d H:i:s',(int)(float)$value);$date=date_create_immutable($value);return$date?$date->format('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s');}
    private function uuid():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}
}
