<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

final class SmsPreferenceService
{
    public function __construct(private ChatStore $store,private ProviderSecretCipher $cipher) {}

    public function state(string $connection,string $peerHash): array
    {
        $pending=(bool)$this->store->rows("SELECT id FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND kind='inbound' AND preference_processed=0 LIMIT 1",[...$this->store->scope(),$connection]);
        $rows=$this->store->rows("SELECT DISTINCT opt_out_type FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND peer_hash=? AND opt_out_type IN ('STOP','START')",[...$this->store->scope(),$connection,$peerHash]);
        $types=array_column($rows,'opt_out_type');
        return ['blocked'=>in_array('STOP',$types,true),'pending'=>$pending,'optInReceived'=>in_array('START',$types,true)];
    }
    public function reviewSnapshot(string $connection,string $peerHash): array
    {
        $rows=$this->store->rows("SELECT id,opt_out_type,received_at FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND peer_hash=? AND opt_out_type IN ('STOP','START') ORDER BY received_at,id",[...$this->store->scope(),$connection,$peerHash]);
        $stops=[];$lastStop=null;$start=null;
        foreach($rows as $row){if($row['opt_out_type']==='STOP'){$stops[]=$row['id'];$lastStop=$row['received_at'];}else $start=$row;}
        sort($stops,SORT_STRING);
        return ['fingerprint'=>hash('sha256',json_encode($stops,JSON_THROW_ON_ERROR)),'stopAt'=>$lastStop,
            'startId'=>$start&&$lastStop&&$start['received_at']>$lastStop?$start['id']:null,
            'startAt'=>$start&&$lastStop&&$start['received_at']>$lastStop?$start['received_at']:null];
    }
    public function released(string $connection,string $peerHash,string $contact,string $purpose,string $event): bool
    {
        $snapshot=$this->reviewSnapshot($connection,$peerHash);
        if(!$snapshot['startId'])return false;
        return (bool)$this->store->rows("SELECT id FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND action='sms.suppression_released' AND subject_type='NexaMessagingConnection' AND subject_id=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.peerHash'))=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.contactId'))=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.purposeId'))=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.consentEventId'))=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.stopFingerprint'))=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.startReceiptId'))=? LIMIT 1",[...$this->store->scope(),$connection,$peerHash,$contact,$purpose,$event,$snapshot['fingerprint'],$snapshot['startId']]);
    }
    public function assertAllowed(string $connection,string $peer,?string $contact=null,?string $purpose=null,?string $event=null): void
    {
        $state=$this->state($connection,SmsPreference::peerHash($connection,$peer));
        if($state['pending'])throw new Forbidden('SMS preference recovery must finish before preparing messages.');
        if($state['blocked']&&(!$contact||!$purpose||!$event||!$this->released($connection,SmsPreference::peerHash($connection,$peer),$contact,$purpose,$event)))throw new Forbidden('This recipient has opted out of SMS from this sender.');
    }
    /** Existing receipts must be indexed before any outgoing admission can trust their absence. */
    public function recover(): void
    {
        $rows=$this->store->rows("SELECT id FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND kind='inbound' AND preference_processed=0 AND (projection_retry_at IS NULL OR projection_retry_at<=UTC_TIMESTAMP()) ORDER BY received_at,id LIMIT 100",$this->store->scope());
        foreach($rows as $item)$this->store->atomic(function()use($item):void {
            $row=$this->store->rows('SELECT * FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$item['id']])[0]??null;
            if(!$row||$row['preference_processed']||($row['projection_retry_at']!==null&&$row['projection_retry_at']>gmdate('Y-m-d H:i:s')))return;
            try{
                [$tenant,$service]=$this->store->scope();
                $event=json_decode($this->cipher->decryptFor($row['payload_encrypted'],$tenant,$service,'messaging-receipt:'.$row['id']),true,32,JSON_THROW_ON_ERROR);
                if(!is_array($event)||($event['kind']??null)!=='inbound'||!in_array($event['channel']??null,['sms','whatsapp'],true)||!preg_match(($event['channel']??null)==='whatsapp'?'/^whatsapp:\+[1-9][0-9]{1,14}$/D':'/^\+[1-9][0-9]{1,14}$/D',$event['from']??''))throw new \RuntimeException('Invalid messaging preference receipt.');
                $type=SmsPreference::type($event);$hash=SmsPreference::peerHash($row['connection_id'],$event['from']);
            }catch(\Throwable $error){
                $delay=min(3600,30*(2**min(7,(int)$row['projection_attempts'])));
                $this->store->execute("UPDATE nexa_messaging_receipt SET projection_attempts=projection_attempts+1,projection_retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),projection_error_code='preference_failed' WHERE tenant_id=? AND service_id=? AND id=?",[$delay,...$this->store->scope(),$row['id']]);return;
            }
            $this->store->execute("UPDATE nexa_messaging_receipt SET peer_hash=?,opt_out_type=?,preference_processed=1,projection_retry_at=IF(projection_error_code='preference_failed',NULL,projection_retry_at),projection_error_code=IF(projection_error_code='preference_failed',NULL,projection_error_code) WHERE tenant_id=? AND service_id=? AND id=?",[$hash,$type,...$this->store->scope(),$row['id']]);
        },true);
    }
}
