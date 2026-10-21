<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Custom\Tools\Contact\CommunicationRestrictionGuard;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

/** Native conversation ACL and canonical consent are rechecked at each outbound boundary. */
final class SmsOutboxService
{
    public function __construct(private ChatStore $store,private User $user,private Acl $acl,private ServiceFactory $records,
        private CommunicationRestrictionGuard $restrictions,private SmsPreferenceService $preferences,
        private ProviderSecretCipher $cipher,private Config $config,private MessagingCallbackService $callbacks,
        private WhatsAppDispatchPolicy $whatsApp,private string $channel='sms')
    { if(!in_array($channel,['sms','whatsapp'],true))throw new \InvalidArgumentException('Unsupported messaging channel.'); }

    public function root(string $id,string $action='read'): Entity
    {
        if(!preg_match('/^[a-zA-Z0-9]{1,17}$/D',$id))throw new NotFound('SMS conversation unavailable.');
        if(!in_array($this->user->getType(),['admin','regular','api'],true)||[$this->user->get('tenantId'),$this->user->get('serviceId')]!==$this->store->scope()||!$this->acl->checkScope('NexaMessagingConversation','read')||!$this->acl->checkField('NexaMessagingConversation','messages','read'))throw new Forbidden('SMS permission is required.');
        foreach(['name','status','assignedUser'] as $field)if(!$this->acl->checkField('NexaMessagingConversation',$field,'read'))throw new Forbidden('SMS property access is required.');
        if(!$this->store->rows("SELECT u.id FROM user u JOIN nexa_tenant t ON t.id=u.tenant_id AND t.status='active' JOIN nexa_tenant_service ts ON ts.tenant_id=u.tenant_id AND ts.service_id=u.service_id AND ts.status='active' JOIN nexa_service_definition d ON d.id=u.service_id AND d.status='active' WHERE u.tenant_id=? AND u.service_id=? AND u.id=? AND u.type=? AND u.is_active=1 AND u.deleted=0",[...$this->store->scope(),$this->user->getId(),$this->user->getType()]))throw new Forbidden('An active staff member and workspace are required.');
        $root=$this->records->createForUser('NexaMessagingConversation',$this->user)->getEntity($id);
        if(!$root||[$root->get('tenantId'),$root->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($root,$action))throw new NotFound('SMS conversation unavailable.');
        if(!$this->store->rows("SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND provider='twilio' AND channel=?",[...$this->store->scope(),$root->get('connectionId'),$this->channel]))throw new NotFound('Messaging conversation unavailable.');
        if($action==='edit'&&!$this->acl->checkField('NexaMessagingConversation','messages','edit'))throw new Forbidden('SMS message edit permission is required.');
        return $root;
    }
    public function check(string $id,string $purposeId,bool $review=false): array
    {
        $root=$this->root($id,'edit');$contactId=$root->get('contactId');
        if(!$contactId||!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read')||!$this->acl->checkScope('Contact','read'))throw new Forbidden('A readable linked Contact is required.');
        foreach(['phoneNumber','name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))throw new Forbidden('Contact property access is required.');
        $contact=$this->records->createForUser('Contact',$this->user)->getEntity($contactId);
        if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,'read'))throw new NotFound('Contact unavailable.');
        $connection=$this->store->rows("SELECT * FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND provider='twilio' AND channel=? AND status<>'disabled'",[...$this->store->scope(),$root->get('connectionId'),$this->channel])[0]??null;
        if(!$connection)throw new Forbidden('SMS connection unavailable.');
        $phone=$this->store->rows("SELECT p.name,p.invalid,p.opt_out FROM entity_phone_number e JOIN phone_number p ON p.id=e.phone_number_id AND p.tenant_id=e.tenant_id AND p.service_id=e.service_id WHERE e.tenant_id=? AND e.service_id=? AND e.entity_type='Contact' AND e.entity_id=? AND e.deleted=0 AND e.`primary`=1 AND p.deleted=0 LIMIT 1",[...$this->store->scope(),$contactId])[0]??null;
        $peer=$this->cipher->decryptFor($root->get('peerEncrypted'),...[$this->store->scope()[0],$this->store->scope()[1],'messaging-peer:'.$connection['id']]);
        if(!$phone||$phone['invalid']||$phone['opt_out']||(($this->channel==='whatsapp'?'whatsapp:':'').$phone['name'])!==$peer)throw new Forbidden('The Contact needs a valid matching primary number.');
        $this->restrictions->assertContactAllowed($contactId,$this->channel);
        $purposes=$this->purposes($contactId);$purpose=null;
        foreach($purposes as $item)if($item['id']===$purposeId)$purpose=$item;
        if(!$purpose)throw new Forbidden('Current SMS consent for the selected purpose is required.');
        if(!$review)$this->preferences->assertAllowed($connection['id'],$peer,$contactId,$purposeId,$purpose['source_event_id']);
        return ['root'=>$root,'contact'=>$contact,'connection'=>$connection,'recipient'=>$peer,'purpose'=>$purpose];
    }
    private function purposes(string $contactId): array
    {
        return $this->store->rows("SELECT p.id,p.name,p.purpose_key,s.source_event_id FROM nexa_consent_state s JOIN nexa_consent_purpose p ON p.id=s.purpose_id AND p.tenant_id=s.tenant_id AND p.service_id=s.service_id JOIN nexa_consent_event e ON e.id=s.source_event_id AND e.tenant_id=s.tenant_id AND e.service_id=s.service_id AND e.contact_id=s.contact_id AND e.purpose_id=s.purpose_id AND e.channel=s.channel AND e.status=s.status AND e.legal_basis=s.legal_basis AND e.policy_version=s.policy_version JOIN contact c ON c.id=s.contact_id AND c.tenant_id=s.tenant_id AND c.service_id=s.service_id WHERE s.tenant_id=? AND s.service_id=? AND s.contact_id=? AND c.deleted=0 AND s.channel=? AND p.is_active=1 AND JSON_CONTAINS(p.channels_json,?)=1 AND s.effective_at<=UTC_TIMESTAMP(6) AND (s.expires_at IS NULL OR s.expires_at>UTC_TIMESTAMP(6)) AND s.policy_version=p.policy_version AND e.voided_at IS NULL AND e.superseded_by_event_id IS NULL AND TRIM(COALESCE(e.evidence_note,''))<>'' AND ((s.status='granted' AND s.legal_basis='FreelyGivenConsent') OR (s.status='not_required' AND s.legal_basis='PerformanceOfContract' AND p.purpose_key='customer_service' AND p.is_system=1)) AND (p.purpose_key<>'marketing_communications' OR c.marketing_status='Marketing') ORDER BY p.position,p.name LIMIT 100",[...$this->store->scope(),$contactId,$this->channel,json_encode($this->channel)]);
    }
    public function enabled(array $connection): bool
    {
        if($connection['channel']!==$this->channel||$this->config->get($this->channel==='whatsapp'?'nexaWhatsAppDispatchEnabled':'nexaSmsDispatchEnabled')!==true||!(bool)$connection['dispatch_enabled']||$connection['status']!=='verified'||!$connection['provider_resource_sid']||!$connection['verified_at']||strtotime($connection['verified_at'].' UTC')<time()-86400)return false;
        try{$this->callbacks->callbackUrl($connection['id'],'status');return true;}catch(\Throwable $error){return false;}
    }
    public function view(string $id): array
    {
        $root=$this->root($id);$canCompose=false;$enabled=false;$purposes=[];$reason='SMS message edit permission and a linked Contact are required.';
        try{
            $eligible=$root->get('contactId')?$this->purposes($root->get('contactId')):[];$purposes=[];
            foreach($eligible as $item){try{$check=$this->check($id,$item['id']);$purposes[]=$item;}catch(Forbidden|NotFound $error){}}
            if(!$purposes)throw new Forbidden('No current eligible SMS consent purpose.');
            $canCompose=true;$enabled=$this->enabled($check['connection']);$reason=$enabled?'':'Sending is not activated. Drafts remain held.';
        }catch(Forbidden|NotFound $error){$purposes=[];$reason='SMS preparation is unavailable under the current Contact, consent or permission settings.';}
        $rows=$this->store->rows('SELECT * FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND conversation_id=? ORDER BY created_at DESC,id DESC LIMIT 20',[...$this->store->scope(),$id]);$outgoing=[];
        foreach($rows as $row){try{$body=$this->payload($row)['body'];}catch(\Throwable $error){$body='Message content unavailable';}
            $outgoing[]=['id'=>$row['id'],'body'=>$body,'status'=>$row['status'],'deliveryStatus'=>$row['delivery_status'],'error'=>$row['last_error'],'version'=>(int)$row['version_number'],'createdAt'=>$row['created_at'],'canCancel'=>$this->acl->checkEntity($root,'edit')&&$this->acl->checkField('NexaMessagingConversation','messages','edit')&&in_array($row['status'],['held','queued'],true),'canRelease'=>$canCompose&&$enabled&&$row['status']==='held'];
        }
        $templates=[];
        if($this->channel==='whatsapp'&&isset($check))$templates=array_values(json_decode($check['connection']['whatsapp_templates_json']??'[]',true,32,JSON_THROW_ON_ERROR));
        return ['canCompose'=>$canCompose,'dispatchEnabled'=>$enabled,'reason'=>$reason,'purposes'=>array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name']],$purposes),'outgoing'=>$outgoing,'templates'=>$templates];
    }
    public function prepare(stdClass $input): array
    {
        $allowed=['op','id','version','requestKey','purposeId','body','queue'];if($this->channel==='whatsapp')array_push($allowed,'contentSid','variables');
        if(array_diff(array_keys((array)$input),$allowed)||!is_string($input->id??null)||!is_int($input->version??null)||!is_string($input->body??null)||!is_string($input->purposeId??null)||!is_bool($input->queue??null))throw new BadRequest('Complete the message and purpose.');
        ChatStore::uuidValue($input->requestKey??null);ChatStore::uuidValue($input->purposeId);$this->root($input->id,'edit');
        $proof=null;
        if($this->channel==='whatsapp'){
            if(!is_string($input->contentSid??'')||!is_object($input->variables??new stdClass()))throw new BadRequest('Choose a template and its values.');
            $proof=$this->whatsApp->inspect($this->check($input->id,$input->purposeId),['body'=>$input->body,'contentSid'=>$input->contentSid??'','variables'=>(array)($input->variables??new stdClass())]);
        }
        return $this->store->atomic(function()use($input,$proof):array{
            $this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$input->id]);
            $check=$this->check($input->id,$input->purposeId);$root=$check['root'];$c=$check['connection'];
            if((int)$root->get('versionNumber')!==$input->version)throw new Conflict('The conversation changed. Refresh before preparing SMS.');
            if($input->queue&&!$this->enabled($c))throw new Forbidden('SMS sending is not activated.');
            $message=$proof?$this->whatsApp->assertCurrent($proof,$check):['body'=>$input->body,'recipient'=>$check['recipient']];
            (new TwilioMessagingAdapter())->outbound($c['account_sid'],$this->channel,$c['sender'],$check['recipient'],$message['body'],'https://example.invalid/status');
            $hash=hash('sha256',json_encode($proof?[$input->id,$root->get('contactId'),$input->purposeId,$message,$input->queue]:[$input->id,$root->get('contactId'),$input->purposeId,$check['recipient'],$input->body,$input->queue],JSON_THROW_ON_ERROR));
            $old=$this->store->rows('SELECT id,intent_hash,status FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND request_key=?',[...$this->store->scope(),$input->requestKey])[0]??null;
            if($old){if(!hash_equals($old['intent_hash'],$hash))throw new Conflict('This SMS request key was already used.');return ['id'=>$old['id'],'status'=>$old['status'],'duplicate'=>true];}
            if(count($this->store->rows("SELECT id FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND created_at>=UTC_DATE() LIMIT 1000",$this->store->scope()))>=1000)throw new BadRequest('Daily SMS preparation limit reached.');
            if(count($this->store->rows("SELECT id FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND status IN ('held','queued','submitting','uncertain') LIMIT 1000",$this->store->scope()))>=1000)throw new BadRequest('SMS queue capacity reached.');
            $id=ChatStore::uuid();$payload=$this->cipher->encryptFor(json_encode($message,JSON_THROW_ON_ERROR),...[$this->store->scope()[0],$this->store->scope()[1],'sms-outbox:'.$id]);
            $status=$input->queue?'queued':'held';
            $this->store->execute("INSERT INTO nexa_messaging_outbox (id,tenant_id,service_id,connection_id,contact_id,conversation_id,purpose_id,consent_event_id,request_key,intent_hash,body,recipient,message_encrypted,connection_version,created_by_id,status,created_at,next_attempt_at) VALUES (?,?,?,?,?,?,?,?,?,?,'','',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$id,...$this->store->scope(),$c['id'],$root->get('contactId'),$root->getId(),$input->purposeId,$check['purpose']['source_event_id'],$input->requestKey,$hash,$payload,$c['version_number'],$this->user->getId(),$status]);
            $this->audit($id,'sms.'.$status,['conversationId'=>$root->getId()]);return ['id'=>$id,'status'=>$status,'duplicate'=>false];
        },true);
    }
    public function change(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','outboxId','version'])||!in_array($input->op??null,['releaseSms','cancelSms'],true)||!is_string($input->id??null)||!is_int($input->version??null))throw new BadRequest('Provide the current SMS revision.');
        ChatStore::uuidValue($input->outboxId??null);$this->root($input->id,'edit');
        $proof=null;
        if($this->channel==='whatsapp'&&$input->op==='releaseSms'){
            $snapshot=$this->store->rows('SELECT * FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND conversation_id=? AND id=?',[...$this->store->scope(),$input->id,$input->outboxId])[0]??null;
            if(!$snapshot)throw new NotFound('Message unavailable.');
            $proof=$this->providerProof($snapshot);
        }
        return $this->store->atomic(function()use($input,$proof):array{
            $row=$this->store->rows('SELECT * FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND conversation_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$input->id,$input->outboxId])[0]??null;$this->root($input->id,'edit');
            if(!$row)throw new NotFound('SMS unavailable.');
            if((int)$row['version_number']!==$input->version||!in_array($row['status'],['held','queued'],true))throw new Conflict('SMS has changed or is already in flight.');
            $status='cancelled';
            if($input->op==='releaseSms'){
                if($row['status']!=='held'||!$row['message_encrypted'])throw new Conflict('Only a current SMS draft can be queued.');
                $check=$this->check($input->id,$row['purpose_id']);$message=$this->payload($row);
                if($proof)$this->whatsApp->assertCurrent($proof,$check,$message);
                if(!$this->enabled($check['connection'])||$check['recipient']!==$message['recipient']||$check['root']->get('contactId')!==$row['contact_id']||$check['purpose']['source_event_id']!==$row['consent_event_id']||(int)$check['connection']['version_number']!==(int)$row['connection_version'])throw new Forbidden('SMS policy or connection changed. Create a new message.');
                $status='queued';
            }
            $this->store->execute('UPDATE nexa_messaging_outbox SET status=?,version_number=version_number+1,next_attempt_at=UTC_TIMESTAMP(),modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[$status,...$this->store->scope(),$row['id']]);
            $this->audit($row['id'],'sms.'.$status,[]);return ['status'=>$status];
        },true);
    }
    public function payload(array $row): array
    {
        return json_decode($this->cipher->decryptFor($row['message_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'sms-outbox:'.$row['id']]),true,16,JSON_THROW_ON_ERROR);
    }
    public function providerProof(array $row): ?array
    {
        if($this->channel!=='whatsapp')return null;
        return $this->whatsApp->inspect($this->check($row['conversation_id'],$row['purpose_id']),$this->payload($row));
    }
    public function assertProviderProof(?array $proof,array $check,array $message): void
    {
        if($this->channel==='whatsapp'){
            if(!$proof)throw new Forbidden('Fresh WhatsApp provider proof is required.');
            $this->whatsApp->assertCurrent($proof,$check,$message);
        }
    }
    public function contactHistory(string $contactId): array
    {
        if(!$this->acl->checkScope('Contact','read')||!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read'))return [];
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))return [];
        $contact=$this->records->createForUser('Contact',$this->user)->getEntity($contactId);
        if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,'read'))return [];
        $rows=$this->store->rows("SELECT o.* FROM nexa_messaging_outbox o JOIN nexa_messaging_conversation c ON c.id=o.conversation_id AND c.tenant_id=o.tenant_id AND c.service_id=o.service_id AND c.contact_id=o.contact_id WHERE o.tenant_id=? AND o.service_id=? AND o.contact_id=? AND c.deleted=0 AND EXISTS (SELECT 1 FROM nexa_messaging_connection p WHERE p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.provider='twilio' AND p.channel=?) AND o.status='submitted' AND o.provider_message_id IS NOT NULL ORDER BY o.submitted_at DESC,o.id DESC LIMIT 200",[...$this->store->scope(),$contactId,$this->channel]);$list=[];
        foreach($rows as $row){
            try{$root=$this->root($row['conversation_id']);if($root->get('contactId')!==$contactId)continue;$body=$this->payload($row)['body'];}
            catch(Forbidden|NotFound $error){continue;}catch(\Throwable $error){$body=null;}
            $list[]=['id'=>$row['id'],'conversationId'=>$row['conversation_id'],'connectionName'=>$root->get('name'),'body'=>$body,'createdAt'=>$row['submitted_at']??$row['created_at'],'mediaCount'=>0,'direction'=>'outgoing','deliveryStatus'=>$row['delivery_status']];
        }
        return $list;
    }
    private function audit(string $id,string $action,array $metadata): void
    {
        if($this->channel==='whatsapp')$action=preg_replace('/^sms\./','whatsapp.',$action);
        $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,?,'NexaMessagingOutbox',?,?,'sms-composer',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$this->user->getId(),$action,$id,ChatStore::uuid(),json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
}
