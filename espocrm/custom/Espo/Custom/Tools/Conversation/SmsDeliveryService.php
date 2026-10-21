<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Custom\Tools\Auth\ProviderSecretCipher;

/** Exact attempt binding, never a phone/body/time-window guess. */
final class SmsDeliveryService
{
    public function __construct(private ChatStore $store,private ProviderSecretCipher $cipher) {}
    public function apply(string $attempt,string $sid,string $status,string $from,string $to,string $connection): bool
    {
        return $this->store->atomic(function()use($attempt,$sid,$status,$from,$to,$connection):bool{
            $a=$this->store->rows('SELECT * FROM nexa_sms_attempt WHERE tenant_id=? AND service_id=? AND id=? AND connection_id=? FOR UPDATE',[...$this->store->scope(),$attempt,$connection])[0]??null;
            if(!$a||$a['status']==='rejected')return false;
            $row=$this->store->rows('SELECT o.*,c.sender,c.channel FROM nexa_messaging_outbox o JOIN nexa_messaging_connection c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.service_id=o.service_id WHERE o.tenant_id=? AND o.service_id=? AND o.id=? FOR UPDATE',[...$this->store->scope(),$a['outbox_id']])[0]??null;
            if(!$row||$row['attempt_id']!==$attempt||!in_array($row['status'],['submitting','uncertain','submitted'],true)||$row['sender']!==$from||($row['provider_message_id']!==null&&$row['provider_message_id']!==$sid))return false;
            $message=json_decode($this->cipher->decryptFor($row['message_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'sms-outbox:'.$row['id']]),true,16,JSON_THROW_ON_ERROR);
            if($message['recipient']!==$to)return false;
            if($this->store->rows('SELECT id FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND connection_id=? AND provider_message_id=? AND id<>?',[...$this->store->scope(),$connection,$sid,$row['id']]))return false;
            $next=MessagingDeliveryState::reconcile($row['delivery_status']??'accepted',$status);
            if($row['status']==='submitted'&&$row['provider_message_id']===$sid&&$row['delivery_status']===$next)return true;
            $this->store->execute("UPDATE nexa_messaging_outbox SET provider_message_id=?,delivery_status=?,status='submitted',last_error=NULL,submitted_at=COALESCE(submitted_at,UTC_TIMESTAMP()),modified_at=UTC_TIMESTAMP(),version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?",[$sid,$next,...$this->store->scope(),$row['id']]);
            $this->store->execute("UPDATE nexa_sms_attempt SET status='accepted',provider_message_id=?,completed_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[$sid,...$this->store->scope(),$attempt]);
            $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'system',?,'NexaMessagingOutbox',?,?,?,?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$row['channel'].'.delivery_observed',$row['id'],$attempt,$row['channel'].'-provider',json_encode(['status'=>$next,'attemptId'=>$attempt],JSON_THROW_ON_ERROR)]);
            return true;
        },true);
    }
}
