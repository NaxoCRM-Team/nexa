<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\{AclManager,InjectableFactory};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

/** One native tenant job: bounded work, no network transaction and no ambiguous POST retry. */
final class SmsDispatchWorker
{
    public function __construct(private ChatStore $store,private EntityManager $em,private InjectableFactory $factory,
        private AclManager $aclManager,private Config $config,private ProviderSecretCipher $cipher,
        private TwilioSmsTransport $transport,private MessagingCallbackService $callbacks,private SmsDeliveryService $delivery,private WhatsAppDispatchPolicy $whatsApp,
        private string $channel='sms')
    { if(!in_array($channel,['sms','whatsapp'],true))throw new \InvalidArgumentException('Unsupported messaging channel.'); }

    public function run(): int
    {
        $this->store->atomic(function():void{
            $expired=$this->store->rows("SELECT o.id,o.attempt_id FROM nexa_messaging_outbox o JOIN nexa_messaging_connection c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.service_id=o.service_id WHERE o.tenant_id=? AND o.service_id=? AND c.channel=? AND o.status='submitting' AND o.modified_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) LIMIT 100 FOR UPDATE",[...$this->store->scope(),$this->channel]);
            foreach($expired as $row){
                $this->store->execute("UPDATE nexa_messaging_outbox SET status='uncertain',last_error='worker_interrupted',version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$row['id']]);
                $this->store->execute("UPDATE nexa_sms_attempt SET status='uncertain',completed_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=? AND status='submitting'",[...$this->store->scope(),$row['attempt_id']]);
                $this->audit($row['id'],'sms.worker_interrupted',$row['attempt_id']);
            }
        },true);
        if($this->config->get($this->channel==='whatsapp'?'nexaWhatsAppDispatchEnabled':'nexaSmsDispatchEnabled')!==true)return 0;
        $sent=0;
        $rows=$this->store->rows("SELECT o.* FROM nexa_messaging_outbox o JOIN nexa_messaging_connection c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.service_id=o.service_id AND c.channel=? AND c.provider='twilio' WHERE o.tenant_id=? AND o.service_id=? AND o.status='queued' AND (o.next_attempt_at IS NULL OR o.next_attempt_at<=UTC_TIMESTAMP()) ORDER BY o.created_at,o.id LIMIT 20",[$this->channel,...$this->store->scope()]);
        $examined=0;
        foreach($rows as $candidate){
            if($sent>=2)break;
            if($this->channel==='whatsapp'&&$examined++>=4)break;
            $proof=null;
            if($this->channel==='whatsapp'){
                try{
                    $actor=$this->em->getEntityById('User',$candidate['created_by_id']);if(!$actor)throw new \Espo\Core\Exceptions\Forbidden('Actor unavailable.');
                    $service=$this->factory->createWith(SmsOutboxService::class,['user'=>$actor,'acl'=>$this->aclManager->createUserAcl($actor),'channel'=>$this->channel,'config'=>$this->config,'callbacks'=>$this->callbacks,'whatsApp'=>$this->whatsApp]);
                    $proof=$service->providerProof($candidate);
                }catch(\Throwable $error){
                    $blocked=$error instanceof \Espo\Core\Exceptions\Forbidden||$error instanceof \Espo\Core\Exceptions\NotFound||strtotime($candidate['created_at'].' UTC')<time()-86400;
                    $this->store->atomic(function()use($candidate,$blocked):void{
                        $current=$this->store->rows('SELECT status,version_number FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$candidate['id']])[0]??null;
                        if(!$current||$current['status']!=='queued'||(int)$current['version_number']!==(int)$candidate['version_number'])return;
                        $this->store->execute("UPDATE nexa_messaging_outbox SET status=?,last_error=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),modified_at=UTC_TIMESTAMP(),version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=? AND status='queued' AND version_number=?",[$blocked?'blocked':'queued',$blocked?'policy_changed':'provider_lookup_wait',...$this->store->scope(),$candidate['id'],$candidate['version_number']]);
                        $this->audit($candidate['id'],$this->channel.($blocked?'.blocked':'.provider_lookup_wait'),null);
                    },true);
                    continue;
                }
            }
            $claim=$this->claim($candidate['id'],$proof);if(!$claim)continue;$sent++;
            try{
                $secret=$this->cipher->decryptFor($claim['connection']['secret_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging:'.$claim['connection']['id']]);
                $result=$this->transport->send($claim['connection'],$secret,$claim['message'],$this->callbacks->callbackUrl($claim['connection']['id'],'status',$claim['attempt']));
            }catch(\Throwable $error){$result=['outcome'=>'uncertain','error'=>'provider_result_uncertain'];}
            if(($result['outcome']??null)==='accepted'){
                if($this->delivery->apply($claim['attempt'],$result['sid'],$result['status'],$claim['connection']['sender'],$claim['message']['recipient'],$claim['connection']['id']))continue;
                $result=['outcome'=>'uncertain','error'=>'provider_binding_conflict'];
            }
            $this->finish($claim,$result);
        }
        $this->inspectOne();return $sent;
    }
    private function claim(string $id,?array $proof=null): ?array
    {
        return $this->store->atomic(function()use($id,$proof):?array{
            $row=$this->store->rows('SELECT * FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$id])[0]??null;
            if(!$row||$row['status']!=='queued'||($row['next_attempt_at']!==null&&$row['next_attempt_at']>gmdate('Y-m-d H:i:s')))return null;
            try{
                if(!$row['message_encrypted']||strtotime($row['created_at'].' UTC')<time()-86400||(int)$row['attempts']>=3)throw new \RuntimeException('Expired SMS intent.');
                $this->store->rows('SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$row['connection_id']]);
                $this->store->rows("SELECT s.source_event_id FROM nexa_consent_state s JOIN nexa_consent_purpose p ON p.tenant_id=s.tenant_id AND p.service_id=s.service_id AND p.id=s.purpose_id JOIN nexa_consent_event e ON e.tenant_id=s.tenant_id AND e.service_id=s.service_id AND e.id=s.source_event_id WHERE s.tenant_id=? AND s.service_id=? AND s.contact_id=? AND s.purpose_id=? AND s.channel=? FOR UPDATE",[...$this->store->scope(),$row['contact_id'],$row['purpose_id'],$this->channel]);
                $this->store->rows('SELECT id FROM contact WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$row['contact_id']]);
                $actor=$this->em->getEntityById('User',$row['created_by_id']);if(!$actor)throw new \RuntimeException('Actor unavailable.');
                $service=$this->factory->createWith(SmsOutboxService::class,['user'=>$actor,'acl'=>$this->aclManager->createUserAcl($actor),'config'=>$this->config,'callbacks'=>$this->callbacks,'channel'=>$this->channel,'whatsApp'=>$this->whatsApp]);
                $check=$service->check($row['conversation_id'],$row['purpose_id']);$message=$service->payload($row);
                $service->assertProviderProof($proof,$check,$message);
                if(!$service->enabled($check['connection'])||$check['recipient']!==$message['recipient']||$check['root']->get('contactId')!==$row['contact_id']||(int)$check['connection']['version_number']!==(int)$row['connection_version']||$check['purpose']['source_event_id']!==$row['consent_event_id'])throw new \RuntimeException('SMS policy changed.');
            }catch(\Throwable $error){$this->store->execute("UPDATE nexa_messaging_outbox SET status='blocked',last_error='policy_changed',version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$id]);$this->audit($id,'sms.blocked',null);return null;}
            $minute=max(1,min(60,(int)$this->config->get('nexaSmsAttemptsPerMinute',5)));
            $day=max(1,min(10000,(int)$this->config->get('nexaSmsAttemptsPerDay',1000)));
            $quota=$this->store->rows('SELECT SUM(started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)) AS minute_count,SUM(started_at>=UTC_DATE()) AS day_count FROM nexa_sms_attempt WHERE tenant_id=? AND service_id=? AND started_at>=LEAST(UTC_DATE(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))',$this->store->scope())[0];
            if((int)$quota['minute_count']>=$minute||(int)$quota['day_count']>=$day){$this->store->execute("UPDATE nexa_messaging_outbox SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),last_error='quota_wait' WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$id]);return null;}
            $attempt=ChatStore::uuid();
            $this->store->execute('INSERT INTO nexa_sms_attempt (id,tenant_id,service_id,outbox_id,connection_id,started_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())',[$attempt,...$this->store->scope(),$id,$row['connection_id']]);
            $this->store->execute("UPDATE nexa_messaging_outbox SET status='submitting',attempt_id=?,attempts=attempts+1,last_error=NULL,modified_at=UTC_TIMESTAMP(),version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?",[$attempt,...$this->store->scope(),$id]);
            $this->audit($id,'sms.submitting',$attempt);
            return ['id'=>$id,'attempt'=>$attempt,'attempts'=>(int)$row['attempts']+1,'connection'=>$check['connection'],'message'=>$message];
        },true);
    }
    private function finish(array $claim,array $result): void
    {
        $this->store->atomic(function()use($claim,$result):void{
            $row=$this->store->rows('SELECT status,attempt_id FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$claim['id']])[0]??null;
            if(!$row||$row['status']!=='submitting'||$row['attempt_id']!==$claim['attempt'])return;
            $outcome=$result['outcome']??'uncertain';$status=$outcome==='retry'&&$claim['attempts']<3?'queued':($outcome==='failed'||$outcome==='retry'?'failed':'uncertain');
            $delay=min(3600,30*(2**$claim['attempts']));$error=in_array($result['error']??'', ['provider_rate_limit','provider_rejected','provider_binding_conflict'],true)?$result['error']:'provider_result_uncertain';
            $this->store->execute('UPDATE nexa_messaging_outbox SET status=?,last_error=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),modified_at=UTC_TIMESTAMP(),version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?',[$status,$error,$delay,...$this->store->scope(),$claim['id']]);
            $this->store->execute('UPDATE nexa_sms_attempt SET status=?,completed_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[$outcome==='retry'||$outcome==='failed'?'rejected':'uncertain',...$this->store->scope(),$claim['attempt']]);
            $this->audit($claim['id'],'sms.'.$status,$claim['attempt']);
        },true);
    }
    private function audit(string $id,string $action,?string $attempt): void
    {
        if($this->channel==='whatsapp')$action=preg_replace('/^sms\./','whatsapp.',$action);
        $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'system',?,'NexaMessagingOutbox',?,?,'sms-worker',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$action,$id,$attempt??ChatStore::uuid(),json_encode(['attemptId'=>$attempt],JSON_THROW_ON_ERROR)]);
    }
    private function inspectOne(): void
    {
        $row=$this->store->atomic(function():?array{
            $row=$this->store->rows("SELECT o.*,c.account_sid,c.sender,c.secret_encrypted,c.channel FROM nexa_messaging_outbox o JOIN nexa_messaging_connection c ON c.id=o.connection_id AND c.tenant_id=o.tenant_id AND c.service_id=o.service_id JOIN nexa_tenant t ON t.id=o.tenant_id AND t.status='active' JOIN nexa_tenant_service ts ON ts.tenant_id=o.tenant_id AND ts.service_id=o.service_id AND ts.status='active' JOIN nexa_service_definition d ON d.id=o.service_id AND d.status='active' WHERE o.tenant_id=? AND o.service_id=? AND c.channel=? AND c.provider='twilio' AND o.status='submitted' AND o.provider_message_id IS NOT NULL AND o.delivery_status IN ('accepted','queued','sending','sent') AND o.submitted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) AND (o.inspected_at IS NULL OR o.inspected_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)) AND c.status='verified' AND c.dispatch_enabled=1 ORDER BY o.submitted_at,o.id LIMIT 1 FOR UPDATE",[...$this->store->scope(),$this->channel])[0]??null;
            if($row)$this->store->execute('UPDATE nexa_messaging_outbox SET inspected_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$row['id']]);return $row;
        },true);
        if(!$row)return;
        try{
            $message=json_decode($this->cipher->decryptFor($row['message_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'sms-outbox:'.$row['id']]),true,16,JSON_THROW_ON_ERROR);
            $secret=$this->cipher->decryptFor($row['secret_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging:'.$row['connection_id']]);
            $observed=$this->transport->inspect($row,$secret,$row['provider_message_id'],$message);
            if($observed)$this->delivery->apply($row['attempt_id'],$observed['sid'],$observed['status'],$row['sender'],$message['recipient'],$row['connection_id']);
        }catch(\Throwable $error){}
    }
}
