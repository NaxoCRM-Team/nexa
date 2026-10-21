<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Exceptions\{BadRequest,Conflict};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

/** Read watermarks require signed provider echo time, never the local send clock. */
final class MessengerReceiptService
{
    public function __construct(private ChatStore $store,private ProviderSecretCipher $cipher) {}
    public function receive(array $connection,array $event): bool
    {
        $hash=hash('sha256',json_encode($event,JSON_THROW_ON_ERROR));$key=$event['kind']==='echo'?hash('sha256','echo:'.$event['messageId']):$hash;$peerHash=hash('sha256',$connection['id']."\0".$event['peer']);
        $old=$this->store->rows('SELECT id,payload_hash FROM nexa_messenger_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND event_key=?',[...$this->store->scope(),$connection['id'],$key])[0]??null;
        if($old){if($old['payload_hash']!==null&&!hash_equals($old['payload_hash'],$hash))throw new Conflict('Provider echo identity has conflicting evidence.');return false;}
        if(count($this->store->rows('SELECT id FROM nexa_messenger_receipt WHERE tenant_id=? AND service_id=? AND received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) LIMIT 10000',$this->store->scope()))>=10000)throw new BadRequest('Messenger receipt capacity reached.');
        $id=ChatStore::uuid();$encrypted=$this->cipher->encryptFor(json_encode($event,JSON_THROW_ON_ERROR),...[$this->store->scope()[0],$this->store->scope()[1],'messenger-receipt:'.$id]);
        $this->store->execute('INSERT INTO nexa_messenger_receipt (id,tenant_id,service_id,connection_id,peer_hash,event_key,payload_hash,kind,payload_encrypted) VALUES (?,?,?,?,?,?,?,?,?)',[$id,...$this->store->scope(),$connection['id'],$peerHash,$key,$hash,$event['kind'],$encrypted]);
        $this->reconcile($connection['id'],$peerHash);return true;
    }
    public function reconcile(string $connection,string $peerHash): void
    {
        $root=$this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND connection_id=? AND peer_hash=? AND deleted=0',[...$this->store->scope(),$connection,$peerHash])[0]??null;if(!$root)return;
        $receipts=$this->store->rows('SELECT id,payload_encrypted FROM nexa_messenger_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND peer_hash=? ORDER BY received_at DESC,id DESC LIMIT 1000',[...$this->store->scope(),$connection,$peerHash]);
        $echoes=[];$nonces=[];$delivered=[];$deliveryWatermark=0;$readWatermark=0;
        foreach($receipts as $row){$event=json_decode($this->cipher->decryptFor($row['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-receipt:'.$row['id']]),true,16,JSON_THROW_ON_ERROR);
            if($event['kind']==='echo'){$echoes[$event['messageId']]=$event;if(preg_match('/^nexa:([a-f0-9-]{36})$/D',$event['metadata']??'',$m))$nonces[$m[1]]=$event;}
            elseif($event['kind']==='read')$readWatermark=max($readWatermark,$event['watermark']);
            else{foreach($event['mids'] as $mid)$delivered[$mid]=true;$deliveryWatermark=max($deliveryWatermark,$event['watermark']);}
        }
        foreach($this->store->rows("SELECT * FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND connection_id=? AND conversation_id=? AND status IN ('submitting','uncertain','accepted') AND (delivery_status IS NULL OR delivery_status<>'read') ORDER BY created_at DESC,id DESC LIMIT 1000 FOR UPDATE",[...$this->store->scope(),$connection,$root['id']]) as $row){
            $mid=$row['provider_message_id'];$echo=($mid?$echoes[$mid]??null:null)??$nonces[$row['id']]??null;$sentAt=$row['provider_sent_at'];
            if($echo&&($mid===null||$mid===$echo['messageId'])){
                if($mid===null&&$this->store->rows('SELECT id FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND connection_id=? AND provider_message_id=? AND id<>?',[...$this->store->scope(),$connection,$echo['messageId'],$row['id']]))continue;
                $mid=$echo['messageId'];$sentAt??=MessengerContract::date($echo['timestamp']);
            }
            if(!$mid)continue;$state=$row['delivery_status']??'accepted';$next=$state;
            $sentMillis=$sentAt?(int)round((float)(new \DateTimeImmutable($sentAt,new \DateTimeZone('UTC')))->format('U.u')*1000):null;
            if($state!=='read'&&(isset($delivered[$mid])||($sentMillis!==null&&$sentMillis<=$deliveryWatermark)))$next='delivered';
            if($sentMillis!==null&&$sentMillis<=$readWatermark)$next='read';
            if($row['status']==='accepted'&&$row['provider_message_id']===$mid&&$row['provider_sent_at']===$sentAt&&$state===$next)continue;
            $this->store->execute("UPDATE nexa_messenger_reply SET status='accepted',provider_message_id=?,provider_sent_at=?,delivery_status=?,delivered_at=IF(? IN ('delivered','read'),COALESCE(delivered_at,UTC_TIMESTAMP(3)),delivered_at),read_at=IF(?='read',COALESCE(read_at,UTC_TIMESTAMP(3)),read_at),modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[$mid,$sentAt,$next,$next,$next,...$this->store->scope(),$row['id']]);
            $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'system','messenger.delivery_observed','NexaMessengerReply',?,?,'messenger-webhook',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$row['id'],ChatStore::uuid(),json_encode(['status'=>$next],JSON_THROW_ON_ERROR)]);
        }
    }
}
