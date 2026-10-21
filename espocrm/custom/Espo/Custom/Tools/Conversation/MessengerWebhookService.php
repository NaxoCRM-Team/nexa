<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext,TenantContextStore};
use Espo\Core\Exceptions\{Conflict,Forbidden,NotFound,BadRequest};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;

final class MessengerWebhookService
{
    public function __construct(private ChatStore $store,private EntityManager $em,private TenantContextStore $contexts,private ProviderSecretCipher $cipher,private PublicRequestLimiter $limiter,private MessengerReceiptService $receipts,private MessengerMediaService $media) {}
    private function eligible(string $id,bool $proof=true): array
    {
        ChatStore::uuidValue($id);$row=$this->store->rows("SELECT c.* FROM nexa_messaging_connection c JOIN nexa_tenant t ON t.id=c.tenant_id AND t.status='active' JOIN nexa_tenant_service ts ON ts.tenant_id=c.tenant_id AND ts.service_id=c.service_id AND ts.status='active' JOIN nexa_service_definition d ON d.id=c.service_id AND d.status='active' WHERE c.id=? AND c.provider='meta' AND c.channel='messenger' AND c.status<> 'disabled'".($proof?" AND c.status='verified'":''),[$id])[0]??null;
        if(!$row)throw new NotFound('Messenger webhook unavailable.');return $row;
    }
    public function challenge(string $id,string $query): string
    {
        $row=$this->eligible($id,false);$challenge=MessengerContract::challenge($query,$row['messenger_verify_hash']);
        $this->limiter->enforce($row['tenant_id'],$row['service_id'],'messenger-challenge-'.$id,30,60);
        return $this->contexts->runWith(new TenantContext($row['tenant_id'],'public','messenger-challenge','',$row['service_id']),fn()=>$this->store->atomic(function()use($row,$id,$challenge):string{
            $this->store->rows('SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$id]);$current=$this->eligible($id,false);
            if($current['version_number']!==$row['version_number'])throw new Conflict('Messenger settings changed.');
            $this->store->execute('UPDATE nexa_messaging_connection SET messenger_webhook_verified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$id]);return $challenge;
        },true));
    }
    public function receive(string $id,string $raw,string $signature): array
    {
        $anchor=$this->eligible($id);$secret=$this->cipher->decryptFor($anchor['messenger_app_secret'],$anchor['tenant_id'],$anchor['service_id'],'messenger-app:'.$id);
        $messages=MessengerContract::events($raw,$signature,$secret,time());$accepted=0;
        foreach($messages as $message){
            // Shared Meta apps may batch Pages. Route by verified Page and app, never by cookie or PSID alone.
            $target=$this->store->rows("SELECT id FROM nexa_messaging_connection WHERE provider='meta' AND channel='messenger' AND sender=? AND messenger_app_id=? AND status='verified'",[$message['page'],$anchor['messenger_app_id']])[0]??null;
            if(!$target)continue;$row=$this->eligible($target['id']);$targetSecret=$this->cipher->decryptFor($row['messenger_app_secret'],$row['tenant_id'],$row['service_id'],'messenger-app:'.$row['id']);
            if(!hash_equals($targetSecret,$secret))throw new Forbidden('The Page app signature does not match.');
            $this->limiter->enforce($row['tenant_id'],$row['service_id'],'messenger-events-'.$row['id'],120,60);
            $accepted+=(int)$this->contexts->runWith(new TenantContext($row['tenant_id'],'public','messenger-webhook','',$row['service_id']),fn()=>$this->store->atomic(function()use($row,$message):bool{
                $this->store->rows('SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$row['id']]);$current=$this->eligible($row['id']);if($current['version_number']!==$row['version_number'])throw new Conflict('Messenger connection changed.');
                if(isset($message['kind']))return $this->receipts->receive($row,$message);
                $key=hash('sha256',$message['messageId']);$hash=hash('sha256',json_encode($message,JSON_THROW_ON_ERROR));
                $old=$this->store->rows('SELECT payload_hash FROM nexa_messenger_event WHERE tenant_id=? AND service_id=? AND connection_id=? AND event_key=?',[...$this->store->scope(),$row['id'],$key])[0]??null;
                if($old){if(!hash_equals($old['payload_hash'],$hash))throw new Conflict('Messenger message identity has conflicting content.');return false;}
                if(count($this->store->rows('SELECT id FROM nexa_messenger_event WHERE tenant_id=? AND service_id=? AND received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) LIMIT 10000',$this->store->scope()))>=10000)throw new BadRequest('Messenger receipt capacity reached.');
                $peerHash=hash('sha256',$row['id']."\0".$message['peer']);$rootRow=$this->store->rows('SELECT id,deleted FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND connection_id=? AND peer_hash=? FOR UPDATE',[...$this->store->scope(),$row['id'],$peerHash])[0]??null;
                if($rootRow&&$rootRow['deleted'])throw new Conflict('This conversation requires administrator review.');
                $root=$rootRow?$this->em->getEntityById('NexaMessagingConversation',$rootRow['id']):$this->em->createEntity('NexaMessagingConversation',['name'=>$row['name'],'status'=>'Open','connectionId'=>$row['id'],'peerHash'=>$peerHash,'peerEncrypted'=>$this->cipher->encryptFor($message['peer'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging-peer:'.$row['id']])],['skipCreatedBy'=>true,'skipModifiedBy'=>true]);
                $eventId=ChatStore::uuid();$payload=$this->cipher->encryptFor(json_encode($message,JSON_THROW_ON_ERROR),...[$this->store->scope()[0],$this->store->scope()[1],'messenger-event:'.$eventId]);
                $at=gmdate('Y-m-d H:i:s',intdiv($message['timestamp'],1000)).sprintf('.%03d',$message['timestamp']%1000);
                $optOut=in_array(strtoupper(trim($message['body'])),['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','REVOKE','OPTOUT'],true);
                $this->store->execute('INSERT INTO nexa_messenger_event (id,tenant_id,service_id,connection_id,conversation_id,event_key,payload_hash,payload_encrypted,is_opt_out,provider_at,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))',[$eventId,...$this->store->scope(),$row['id'],$root->getId(),$key,$hash,$payload,(int)$optOut,$at]);
                if(in_array(strtoupper(trim($message['body'])),['START','UNSTOP','SUBSCRIBE'],true))$this->store->execute('UPDATE nexa_messenger_event SET is_opt_in=1 WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$eventId]);
                $this->media->admit($eventId,$root->getId(),$message['media']??[]);
                $this->store->execute('UPDATE nexa_messaging_connection SET messenger_webhook_verified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$row['id']]);return true;
            },true));
        }return ['accepted'=>$accepted];
    }
}
