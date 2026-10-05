<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;
use Espo\Core\Exceptions\BadRequest;use Espo\Core\Exceptions\Forbidden;use Espo\Core\ORM\EntityManager;use Espo\Core\Tenant\TenantContext;use Espo\Core\Tenant\TenantContextStore;use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;use PDO;use stdClass;
/** Authenticated, idempotent Postal lifecycle reconciliation. */
final class PostalCallbackService
{
    public function __construct(private EntityManager $entityManager,private TenantContextStore $tenantContextStore,private PublicRequestLimiter $limiter){}
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
    private function reconcile(TenantContext $context,stdClass $input,string $encoded):array
    {
        $message=is_object($input->message??null)?$input->message:(is_object($input->original_message??null)?$input->original_message:new stdClass());
        $rawType=(string)($input->event??$input->type??$input->status??'unknown');$type=$this->eventType($rawType,$input);$providerMessageId=trim((string)($message->message_id??$message->id??''));$providerToken=trim((string)($message->token??''));$recipient=mb_strtolower(trim((string)($message->to??$input->recipient??'')));$timestamp=(string)($input->timestamp??$message->timestamp??'');
        $eventId=hash('sha256',implode('|',[$rawType,$providerMessageId,$providerToken,$recipient,$timestamp]));$occurred=$this->occurredAt($timestamp);$pdo=$this->entityManager->getPDO();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{$profileId=null;if($providerMessageId!==''||$providerToken!==''){$find=$pdo->prepare('SELECT profile_id FROM nexa_marketing_delivery_message WHERE tenant_id=? AND service_id=? AND (provider_message_id=? OR provider_token=?) LIMIT 1');$find->execute([$context->tenantId,$context->serviceId,$providerMessageId,$providerToken]);$profileId=$find->fetchColumn()?:null;}
            $insert=$pdo->prepare('INSERT IGNORE INTO nexa_marketing_delivery_event (id,tenant_id,service_id,profile_id,provider_key,provider_event_id,provider_message_id,recipient,event_type,payload_json,occurred_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');$insert->execute([$this->uuid(),$context->tenantId,$context->serviceId,$profileId,'postal',$eventId,$providerMessageId?:null,$recipient?:null,$type,$encoded,$occurred]);if($insert->rowCount()===0){if($ownsTransaction)$pdo->rollBack();return['accepted'=>true,'duplicate'=>true];}
            if($providerMessageId!==''||$providerToken!==''){$status=$this->messageStatus($type);$update=$pdo->prepare('UPDATE nexa_marketing_delivery_message SET status=?,reconciled_at=NOW(6),modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND (provider_message_id=? OR provider_token=?)');$update->execute([$status,$context->tenantId,$context->serviceId,$providerMessageId,$providerToken]);}
            if($recipient!==''&&in_array($type,['bounced','complained'],true)){$reason=$type==='complained'?'complaint':'hard_bounce';$suppress=$pdo->prepare('INSERT INTO nexa_marketing_suppression (id,tenant_id,service_id,email_address,reason,source,provider_message_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),source=VALUES(source),provider_message_id=VALUES(provider_message_id),is_active=1,released_at=NULL,released_by_id=NULL');$suppress->execute([$this->uuid(),$context->tenantId,$context->serviceId,$recipient,$reason,'postal',$providerMessageId?:null]);}
            $column=in_array($type,['delivered'],true)?'delivered_count':(in_array($type,['bounced','failed','complained'],true)?'failed_count':null);if($column){$usage=$pdo->prepare("INSERT INTO nexa_marketing_monthly_usage (tenant_id,service_id,usage_month,{$column}) VALUES (?,?,DATE_FORMAT(?,'%Y-%m-01'),1) ON DUPLICATE KEY UPDATE {$column}={$column}+1");$usage->execute([$context->tenantId,$context->serviceId,$occurred]);}
            if($ownsTransaction)$pdo->commit();return['accepted'=>true,'duplicate'=>false];}catch(\Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    /** @return array<string,string> */ private function provider(string $token):array{$statement=$this->entityManager->getPDO()->prepare("SELECT id,tenant_id,service_id FROM nexa_marketing_delivery_provider WHERE callback_token_hash=? AND status<>'disabled' LIMIT 1");$statement->execute([hash('sha256',$token)]);$row=$statement->fetch(PDO::FETCH_ASSOC);if(!$row)throw new Forbidden('Callback endpoint is unavailable.');return $row;}
    private function eventType(string $raw,stdClass $input):string{$value=strtolower($raw);if(str_contains($value,'bounce'))return'bounced';if(str_contains($value,'complaint')||str_contains($value,'spam'))return'complained';if(str_contains($value,'delay')||str_contains($value,'defer'))return'deferred';if(str_contains($value,'fail'))return'failed';if(str_contains($value,'sent')||strtolower((string)($input->status??''))==='sent')return'delivered';if(str_contains($value,'load')||str_contains($value,'open'))return'opened';if(str_contains($value,'click'))return'clicked';return'unknown';}
    private function messageStatus(string $type):string{return match($type){'delivered'=>'delivered','deferred'=>'deferred','bounced'=>'bounced','complained'=>'complained','failed'=>'failed',default=>'submitted'};}
    private function occurredAt(string $value):string{if(is_numeric($value))return gmdate('Y-m-d H:i:s',(int)(float)$value);$date=date_create_immutable($value);return$date?$date->format('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s');}
    private function uuid():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}
}
