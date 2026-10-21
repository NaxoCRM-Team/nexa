<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

/** Staff SMS/WhatsApp reads and triage use native ownership, not admin receipt access. */
final class MessagingConversationService
{
    public function __construct(private ChatStore $store,private EntityManager $em,private User $user,private Acl $acl,
        private ServiceFactory $records,private ProviderSecretCipher $cipher,private SmsPreferenceService $preferences,private \Espo\Core\InjectableFactory $factory,
        private string $channel='sms')
    { if(!in_array($channel,['sms','whatsapp'],true))throw new \InvalidArgumentException('Unsupported messaging channel.'); }
    public function process(stdClass $input): array
    {
        $this->access();
        if (strlen(json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>8192) throw new BadRequest('Messaging request exceeds its limit.');
        return match($input->op??'') {
            'list'=>$this->listing($input),'thread'=>$this->thread($input),'owners'=>$this->owners($input),'update'=>$this->update($input),
            'contacts'=>$this->contacts($input),'linkContact'=>$this->linkContact($input),'media'=>$this->mediaFile($input),
            'prepareSms'=>$this->outbox()->prepare($input),'releaseSms','cancelSms'=>$this->outbox()->change($input),
            'outbox'=>$this->outboxView($input),
            'reviewSmsOptIn'=>$this->channel==='sms'?$this->optInReview()->release($input):throw new BadRequest('WhatsApp opt-out requires a separate governed review.'),
            default=>throw new BadRequest('Choose a conversation operation.'),
        };
    }
    private function mediaFile(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','mediaId']))throw new BadRequest('Invalid attachment request.');
        $root=$this->root($input->id??null);
        if(!$this->acl->checkField('NexaMessagingConversation','messages','read'))throw new Forbidden('Message access is required.');
        if(!is_string($input->mediaId??null))throw new NotFound('The attachment is unavailable.');
        return $this->factory->create(IncomingMediaService::class)->file($root->getId(),$input->mediaId,$this->acl);
    }
    private function outbox(): SmsOutboxService
    {
        return $this->factory->createWith(SmsOutboxService::class,['user'=>$this->user,'acl'=>$this->acl,'channel'=>$this->channel]);
    }
    private function outboxView(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id'])||!is_string($input->id??null))throw new BadRequest('Invalid SMS outbox request.');
        return $this->outboxResponse($input->id);
    }
    private function optInReview(): SmsOptInReview
    {
        return $this->factory->createWith(SmsOptInReview::class,['user'=>$this->user,'outbox'=>$this->outbox()]);
    }
    private function outboxResponse(string $id): array
    {
        $root=$this->outbox()->root($id);
        return $this->outbox()->view($id)+['optInReview'=>$this->channel==='sms'?$this->optInReview()->view($id):null,'preference'=>$this->preferences->state($root->get('connectionId'),$root->get('peerHash'))];
    }
    private function access(): void
    {
        if (!$this->user->isActive() || !in_array($this->user->getType(),['admin','regular','api'],true) ||
            [$this->user->get('tenantId'),$this->user->get('serviceId')]!==$this->store->scope() ||
            !$this->acl->checkScope('NexaMessagingConversation','read')) throw new Forbidden('SMS conversation permission is required.');
        $active=$this->store->rows("SELECT u.id FROM user u INNER JOIN nexa_tenant t ON t.id=u.tenant_id AND t.status='active' INNER JOIN nexa_tenant_service ts ON ts.tenant_id=u.tenant_id AND ts.service_id=u.service_id AND ts.status='active' INNER JOIN nexa_service_definition d ON d.id=u.service_id AND d.status='active' WHERE u.tenant_id=? AND u.service_id=? AND u.id=? AND u.is_active=1 AND u.deleted=0 AND u.type=?",[...$this->store->scope(),$this->user->getId(),$this->user->getType()]);
        if (!$active) throw new Forbidden('An active staff member and workspace are required.');
        foreach(['name','status','assignedUser'] as $field) if(!$this->acl->checkField('NexaMessagingConversation',$field,'read')) throw new Forbidden('Conversation property permission is required.');
    }
    private function root(mixed $id,string $action='read'): Entity
    {
        if(!is_string($id)||!preg_match('/^[a-zA-Z0-9]{1,17}$/D',$id)) throw new NotFound('Conversation unavailable.');
        $root=$this->records->createForUser('NexaMessagingConversation',$this->user)->getEntity($id);
        if(!$root || [$root->get('tenantId'),$root->get('serviceId')]!==$this->store->scope() || !$this->acl->checkEntity($root,$action)) throw new NotFound('Conversation unavailable.');
        if(!$this->store->rows("SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND provider='twilio' AND channel=?",[...$this->store->scope(),$root->get('connectionId'),$this->channel]))throw new NotFound('Messaging conversation unavailable.');
        return $root;
    }
    private function summary(Entity $root): array
    {
        $peer=null;
        if($this->acl->checkField('NexaMessagingConversation','peer','read')) $peer=$this->cipher->decryptFor($root->get('peerEncrypted'),...[$this->store->scope()[0],$this->store->scope()[1],'messaging-peer:'.$root->get('connectionId')]);
        return ['id'=>$root->getId(),'name'=>$peer??'SMS contact','connectionName'=>$root->get('name'),'status'=>$root->get('status'),
            'assignedUserId'=>$root->get('assignedUserId'),'version'=>(int)$root->get('versionNumber'),'createdAt'=>$root->get('createdAt')];
    }
    private function listing(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','before','q','status'])) throw new BadRequest('Unknown conversation filter.');
        $before=$input->before??'';$q=$input->q??'';$status=$input->status??'';
        if(!is_string($before)||($before!==''&&!preg_match('/^[a-zA-Z0-9]{1,17}$/D',$before))||!is_string($q)||mb_strlen($q)>100||!in_array($status,['','Open','Pending','Closed'],true)) throw new BadRequest('Choose valid conversation filters.');
        $rows=$this->store->rows("SELECT c.id FROM nexa_messaging_conversation c JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.channel=? AND p.provider='twilio' WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND c.id<? ORDER BY c.id DESC LIMIT 100",[$this->channel,...$this->store->scope(),$before?:str_repeat('z',17)]);
        $list=[];
        foreach($rows as $row) {
            try{$root=$this->root($row['id']);}catch(Forbidden|NotFound $error){continue;}
            $item=$this->summary($root);
            if(($status!==''&&$item['status']!==$status)||($q!==''&&mb_stripos($item['name'].' '.$item['connectionName'],$q)===false)) continue;
            $list[]=$item;
        }
        return ['list'=>$list,'nextCursor'=>count($rows)===100?end($rows)['id']:null,'canConfigure'=>$this->user->isAdmin()];
    }
    private function thread(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','before'])) throw new BadRequest('Unknown conversation request.');
        $root=$this->root($input->id??null);
        if(!$this->acl->checkField('NexaMessagingConversation','messages','read')) throw new Forbidden('Message access is required.');
        $before=$input->before??'';$args=[...$this->store->scope(),$root->getId(),$root->get('connectionId')];$filter='';
        if(!is_string($before)) throw new BadRequest('Choose a valid message cursor.');
        if($before!=='') {
            ChatStore::uuidValue($before);
            $anchor=$this->store->rows('SELECT received_at,id FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND conversation_id=? AND connection_id=? AND id=?',[...$args,$before])[0]??null;
            if(!$anchor) throw new NotFound('Message cursor unavailable.');
            $filter=' AND (received_at<? OR (received_at=? AND id<?))';array_push($args,$anchor['received_at'],$anchor['received_at'],$before);
        }
        $rows=$this->store->rows("SELECT id,payload_encrypted,received_at,media_index_error FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND conversation_id=? AND connection_id=? AND kind='inbound'".$filter.' ORDER BY received_at DESC,id DESC LIMIT 51',$args);
        $more=count($rows)>50;$rows=array_reverse(array_slice($rows,0,50));$messages=[];
        foreach($rows as $row) {
            $event=json_decode($this->cipher->decryptFor($row['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging-receipt:'.$row['id']]),true,32,JSON_THROW_ON_ERROR);
            $messages[]=['id'=>$row['id'],'body'=>$event['body'],'createdAt'=>$row['received_at'],'mediaCount'=>count($event['media']),'mediaTypes'=>array_column($event['media'],'contentType'),'media'=>$this->factory->create(IncomingMediaService::class)->listing($row['id']),'mediaError'=>$row['media_index_error']];
        }
        $owner=null;
        if($root->get('assignedUserId'))try{$owner=$this->staff($root->get('assignedUserId'))->get('name');}catch(Forbidden|NotFound $error){}
        return ['conversation'=>$this->summary($root)+['ownerName'=>$owner],'messages'=>$messages,'hasMore'=>$more,'canReply'=>false,
            'smsPreference'=>$this->preferences->state($root->get('connectionId'),$root->get('peerHash')),'smsOutbox'=>$this->outboxResponse($root->getId()),
            'canAssign'=>$this->canAssign($root),'contact'=>$this->contactContext($root),'canReadContact'=>$this->canReadContact(),'canLinkContact'=>$this->canLinkContact($root)];
    }
    private function canReadContact(): bool
    {
        if(!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read')||!$this->acl->checkScope('Contact','read'))return false;
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))return false;
        return true;
    }
    public function contactTimeline(string $contactId,int $limit=50): array
    {
        if($this->user->isSystem())return [];
        if(!$this->acl->checkScope('NexaMessagingConversation','read')||!$this->canReadContact()||
            !$this->acl->checkField('NexaMessagingConversation','messages','read')||!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read'))return [];
        $this->access();$this->contact($contactId);$limit=min(200,max(1,$limit));
        $rows=$this->store->rows("SELECT r.id,r.conversation_id,r.connection_id,r.payload_encrypted,r.received_at FROM nexa_messaging_receipt r INNER JOIN nexa_messaging_conversation c ON c.id=r.conversation_id AND c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.connection_id=r.connection_id WHERE c.tenant_id=? AND c.service_id=? AND c.contact_id=? AND c.deleted=0 AND EXISTS (SELECT 1 FROM nexa_messaging_connection p WHERE p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.provider='twilio' AND p.channel=?) AND r.kind='inbound' ORDER BY r.received_at DESC,r.id DESC LIMIT 200",[...$this->store->scope(),$contactId,$this->channel]);
        $roots=[];$list=[];
        foreach($rows as $row){
            if(!array_key_exists($row['conversation_id'],$roots)){
                try{$root=$this->root($row['conversation_id']);$roots[$row['conversation_id']]=$root->get('contactId')===$contactId?$root:null;}
                catch(Forbidden|NotFound $error){$roots[$row['conversation_id']]=null;}
            }
            $root=$roots[$row['conversation_id']];if(!$root)continue;
            try{$event=json_decode($this->cipher->decryptFor($row['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging-receipt:'.$row['id']]),true,32,JSON_THROW_ON_ERROR);}
            catch(\Throwable $error){$event=null;}
            $list[]=['id'=>$row['id'],'conversationId'=>$root->getId(),'connectionName'=>$root->get('name'),
                'body'=>is_array($event)&&is_string($event['body']??null)?$event['body']:null,'createdAt'=>$row['received_at'],
                'mediaCount'=>is_array($event['media']??null)?count($event['media']):0];
            if(count($list)>=$limit)break;
        }
        $list=array_merge($list,$this->outbox()->contactHistory($contactId));
        foreach($list as &$message)$message['channel']=$this->channel;
        unset($message);
        usort($list,fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt'])?:strcmp($b['id'],$a['id']));
        return array_slice($list,0,$limit);
    }
    private function canLinkContact(Entity $root): bool
    {
        if(!$this->acl->checkEntity($root,'edit')||!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read')||
            !$this->acl->checkField('NexaMessagingConversation','contactAssociation','edit')||!$this->acl->checkField('NexaMessagingConversation','messages','read')||
            !$this->acl->checkScope('Contact','read')||!$this->acl->checkScope('Contact','edit'))return false;
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))return false;
        return true;
    }
    private function contact(string $id,string $action='read'): Entity
    {
        if(!preg_match('/^[a-zA-Z0-9]{1,17}$/D',$id))throw new NotFound('Contact unavailable.');
        $contact=$this->records->createForUser('Contact',$this->user)->getEntity($id);
        if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,$action))throw new NotFound('Contact unavailable.');
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))throw new NotFound('Contact unavailable.');
        return $contact;
    }
    private function contactContext(Entity $root): ?array
    {
        if(!$root->get('contactId')||!$this->canReadContact())return null;
        try{$contact=$this->contact($root->get('contactId'));}catch(Forbidden|NotFound $error){return null;}
        return ['id'=>$contact->getId(),'name'=>$contact->get('name')];
    }
    private function contacts(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','q']))throw new BadRequest('Unknown contact filter.');
        $root=$this->root($input->id??null,'edit');
        if(!$this->canLinkContact($root))throw new Forbidden('Contact association permission is required.');
        $q=$input->q??'';if(!is_string($q)||mb_strlen($q)>100)throw new BadRequest('Enter a shorter contact search.');
        if(mb_strlen(trim($q))<2)return ['list'=>[]];
        $pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],trim($q)).'%';$list=[];
        $rows=$this->store->rows("SELECT id FROM contact WHERE tenant_id=? AND service_id=? AND deleted=0 AND CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ? ORDER BY id LIMIT 100",[...$this->store->scope(),$pattern]);
        foreach($rows as $row){try{$contact=$this->contact($row['id'],'edit');}catch(Forbidden|NotFound $error){continue;}$list[]=['id'=>$contact->getId(),'name'=>$contact->get('name')];}
        return ['list'=>$list];
    }
    private function linkContact(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','version','contactId'])||!is_int($input->version??null)||!property_exists($input,'contactId')||!($input->contactId===null||is_string($input->contactId)))throw new BadRequest('Provide the current contact association.');
        $root=$this->root($input->id??null,'edit');
        if(!$this->canLinkContact($root))throw new Forbidden('Contact association permission is required.');
        return $this->store->atomic(function()use($input):array {
            $this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$input->id]);
            $this->access();$root=$this->root($input->id,'edit');
            if(!$this->canLinkContact($root))throw new Forbidden('Contact association permission is required.');
            if((int)$root->get('versionNumber')!==$input->version)throw new Conflict('The conversation changed. Refresh before linking a contact.');
            if($input->contactId!==null){
                if(!$this->store->rows('SELECT id FROM contact WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE',[...$this->store->scope(),$input->contactId]))throw new NotFound('Contact unavailable.');
                $this->contact($input->contactId,'edit');
            }
            $old=$root->get('contactId');
            if($old!==$input->contactId){
                $root->set('contactId',$input->contactId);$this->em->saveEntity($root);
                $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,?,'NexaMessagingConversation',?,?,'sms-workspace',?,UTC_TIMESTAMP(6))",
                    [ChatStore::uuid(),...$this->store->scope(),$this->user->getId(),$input->contactId===null?'sms.contact_unlinked':'sms.contact_linked',$root->getId(),ChatStore::uuid(),json_encode(['previousContactId'=>$old,'contactId'=>$input->contactId,'identityVerified'=>false,'consentChanged'=>false],JSON_THROW_ON_ERROR)]);
            }
            return ['version'=>(int)$root->get('versionNumber'),'contact'=>$this->contactContext($root)];
        },true);
    }
    private function canAssign(Entity $root): bool
    {
        return $this->acl->checkEntity($root,'edit')&&$this->acl->checkField('NexaMessagingConversation','status','edit')&&
            $this->acl->checkField('NexaMessagingConversation','assignedUser','edit')&&$this->acl->getPermissionLevel('assignment')==='all';
    }
    private function staff(string $id): User
    {
        $u=$this->records->createForUser('User',$this->user)->getEntity($id);
        if(!$u instanceof User || !$u->isActive() || !in_array($u->getType(),['admin','regular'],true) || [$u->get('tenantId'),$u->get('serviceId')]!==$this->store->scope() || !$this->acl->checkEntity($u,'read')) throw new NotFound('Staff member unavailable.');
        foreach(['name','firstName','lastName'] as $field) if(!$this->acl->checkField('User',$field,'read')) throw new NotFound('Staff member unavailable.');
        return $u;
    }
    private function owners(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','q'])) throw new BadRequest('Unknown staff filter.');
        $q=$input->q??'';if(!is_string($q)||mb_strlen($q)>100)throw new BadRequest('Enter a shorter staff search.');
        $pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';$list=[];
        foreach($this->store->rows("SELECT id FROM user WHERE tenant_id=? AND service_id=? AND deleted=0 AND is_active=1 AND type IN ('admin','regular') AND CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ? ORDER BY id LIMIT 100",[...$this->store->scope(),$pattern]) as $row) {
            try{$u=$this->staff($row['id']);}catch(Forbidden|NotFound $error){continue;}
            $list[]=['id'=>$u->getId(),'name'=>$u->get('name')];
        }
        return ['list'=>$list];
    }
    private function update(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','version','status','ownerId'])||!is_int($input->version??null)||!in_array($input->status??null,['Open','Pending','Closed'],true)||!property_exists($input,'ownerId')||!($input->ownerId===null||is_string($input->ownerId))) throw new BadRequest('Provide the current conversation state.');
        $root=$this->root($input->id??null,'edit');
        if(!$this->canAssign($root)) throw new Forbidden('Conversation assignment permission is required.');
        return $this->store->atomic(function()use($input):array {
            $this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$input->id]);
            $this->access();$root=$this->root($input->id,'edit');
            if(!$this->canAssign($root))throw new Forbidden('Conversation assignment permission is required.');
            if((int)$root->get('versionNumber')!==$input->version)throw new Conflict('The conversation changed. Refresh before updating it.');
            if($input->ownerId!==null)$this->staff($input->ownerId);
            $previous=['status'=>$root->get('status'),'ownerId'=>$root->get('assignedUserId')];
            if($previous===['status'=>$input->status,'ownerId'=>$input->ownerId])return $this->summary($root);
            $root->set(['status'=>$input->status,'assignedUserId'=>$input->ownerId]);$this->em->saveEntity($root);
            $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,'sms.conversation_updated','NexaMessagingConversation',?,?,'sms-workspace',?,UTC_TIMESTAMP(6))",
                [ChatStore::uuid(),...$this->store->scope(),$this->user->getId(),$root->getId(),ChatStore::uuid(),json_encode(['previous'=>$previous,'status'=>$input->status,'ownerId'=>$input->ownerId],JSON_THROW_ON_ERROR)]);
            return $this->acl->checkEntity($root,'read')?$this->summary($root):['id'=>$root->getId(),'accessLost'=>true];
        },true);
    }
}
