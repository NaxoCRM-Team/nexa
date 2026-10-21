<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\ORM\EntityManager;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

/** Durable receipts remain authoritative; projection never sends or infers Contact identity. */
final class MessagingConversationProjector
{
    public function __construct(private ChatStore $store, private EntityManager $em, private ProviderSecretCipher $cipher,private SmsPreferenceService $preferences,private IncomingMediaService $media) {}
    public function recover(): int
    {
        $active=$this->store->rows("SELECT ts.tenant_id FROM nexa_tenant_service ts INNER JOIN nexa_tenant t ON t.id=ts.tenant_id AND t.status='active' INNER JOIN nexa_service_definition d ON d.id=ts.service_id AND d.status='active' WHERE ts.tenant_id=? AND ts.service_id=? AND ts.status='active'",$this->store->scope());
        if (!$active) return 0;
        $this->preferences->recover();
        $count=0;
        $rows=$this->store->rows("SELECT id,projection_attempts FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND kind='inbound' AND conversation_id IS NULL AND (projection_retry_at IS NULL OR projection_retry_at<=UTC_TIMESTAMP()) ORDER BY received_at,id LIMIT 100",$this->store->scope());
        foreach($rows as $row) {
            $projected=$this->store->atomic(function()use($row):bool {
                try { return $this->project($row['id']); }
                catch(\Throwable $error) {
                    // Keep admission locked while rolling back partial projection and recording its backoff.
                    $current=$this->store->rows('SELECT projection_attempts FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$row['id']])[0]??null;
                    if(!$current)return false;
                    $delay=min(3600,30*(2**min(7,(int)$current['projection_attempts'])));
                    $this->store->execute("UPDATE nexa_messaging_receipt SET projection_attempts=projection_attempts+1,projection_retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),projection_error_code='projection_failed' WHERE tenant_id=? AND service_id=? AND id=? AND conversation_id IS NULL",[$delay,...$this->store->scope(),$row['id']]);
                    return false;
                }
            },true);
            if($projected)$count++;
        }
        return $count;
    }
    private function project(string $id): bool
    {
        return $this->store->atomic(function()use($id):bool {
            $this->store->rows('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE',[$this->store->scope()[0]]);
            if(!$this->store->rows("SELECT ts.tenant_id FROM nexa_tenant_service ts INNER JOIN nexa_tenant t ON t.id=ts.tenant_id AND t.status='active' INNER JOIN nexa_service_definition d ON d.id=ts.service_id AND d.status='active' WHERE ts.tenant_id=? AND ts.service_id=? AND ts.status='active'",$this->store->scope()))return false;
            $row=$this->store->rows("SELECT r.*,c.name AS connection_name FROM nexa_messaging_receipt r INNER JOIN nexa_messaging_connection c ON c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.id=r.connection_id WHERE r.tenant_id=? AND r.service_id=? AND r.id=? AND r.kind='inbound' FOR UPDATE",[...$this->store->scope(),$id])[0]??null;
            if (!$row || $row['conversation_id']!==null || ($row['projection_retry_at']!==null && $row['projection_retry_at']>gmdate('Y-m-d H:i:s'))) return false;
            [$tenant,$service]=$this->store->scope();
            $payload=json_decode($this->cipher->decryptFor($row['payload_encrypted'],$tenant,$service,'messaging-receipt:'.$id),true,32,JSON_THROW_ON_ERROR);
            $channel=$payload['channel']??null;
            if (($payload['kind']??null)!=='inbound' || !in_array($channel,['sms','whatsapp'],true) || !preg_match($channel==='whatsapp'?'/^whatsapp:\+[1-9][0-9]{1,14}$/D':'/^\+[1-9][0-9]{7,14}$/D',$payload['from']??'')) throw new \RuntimeException('Invalid stored inbound receipt.');
            $hash=hash('sha256',$row['connection_id']."\0".$payload['from']);
            $existing=$this->store->rows('SELECT id,deleted FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND connection_id=? AND peer_hash=? FOR UPDATE',[$tenant,$service,$row['connection_id'],$hash])[0]??null;
            if ($existing && $existing['deleted']) throw new \RuntimeException('Messaging conversation requires operator review.');
            if ($existing) {
                $root=$this->em->getEntityById('NexaMessagingConversation',$existing['id']);
                // Delayed recovery of old history must not undo newer staff triage.
                if ($row['received_at'] >= ($root->get('modifiedAt')??$root->get('createdAt')??'')) {
                    $root->set(['status'=>'Open','modifiedAt'=>gmdate('Y-m-d H:i:s'),'modifiedById'=>null]);
                    $this->em->saveEntity($root,['silent'=>true,'skipModifiedBy'=>true]);
                }
            } else {
                $root=$this->em->createEntity('NexaMessagingConversation',['name'=>$row['connection_name'],'status'=>'Open','connectionId'=>$row['connection_id'],
                    'peerHash'=>$hash,'peerEncrypted'=>$this->cipher->encryptFor($payload['from'],$tenant,$service,'messaging-peer:'.$row['connection_id'])],['skipCreatedBy'=>true,'skipModifiedBy'=>true]);
            }
            $this->media->admit($id,$root->getId(),$payload['media']??[]);
            $this->store->execute('UPDATE nexa_messaging_receipt SET conversation_id=?,projection_error_code=NULL,projection_retry_at=NULL WHERE tenant_id=? AND service_id=? AND id=?',[$root->getId(),$tenant,$service,$id]);
            return true;
        },true);
    }
}
