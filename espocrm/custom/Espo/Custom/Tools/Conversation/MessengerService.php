<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Acl;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

final class MessengerService
{
    public function __construct(private ChatStore $store,private User $user,private Acl $acl,private ServiceFactory $records,private MessengerConnectionService $connections,private MessengerClient $client,private ProviderSecretCipher $cipher,private MessengerReceiptService $receipts,private MessengerMediaService $media,private MessengerOptInReview $reviews,private \Espo\Core\ORM\EntityManager $em) {}
    private function access(): void
    {
        if(!in_array($this->user->getType(),['admin','regular','api'],true)||[$this->user->get('tenantId'),$this->user->get('serviceId')]!==$this->store->scope()||!$this->acl->checkScope('NexaMessagingConversation','read'))throw new Forbidden('Messenger conversation access is required.');
        foreach(['name','status','assignedUser','messages'] as $field)if(!$this->acl->checkField('NexaMessagingConversation',$field,'read'))throw new Forbidden('Conversation property access is required.');
        if(!$this->store->rows("SELECT u.id FROM user u JOIN nexa_tenant t ON t.id=u.tenant_id AND t.status='active' JOIN nexa_tenant_service ts ON ts.tenant_id=u.tenant_id AND ts.service_id=u.service_id AND ts.status='active' JOIN nexa_service_definition d ON d.id=u.service_id AND d.status='active' WHERE u.tenant_id=? AND u.service_id=? AND u.id=? AND u.type=? AND u.is_active=1 AND u.deleted=0",[...$this->store->scope(),$this->user->getId(),$this->user->getType()]))throw new Forbidden('An active staff member and workspace are required.');
    }
    private function root(mixed $id,string $action='read'): Entity
    {
        $this->access();if(!is_string($id)||!preg_match('/^[A-Za-z0-9]{1,17}$/D',$id))throw new NotFound('Messenger conversation unavailable.');
        $root=$this->records->createForUser('NexaMessagingConversation',$this->user)->getEntity($id);
        if(!$root||[$root->get('tenantId'),$root->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($root,$action))throw new NotFound('Messenger conversation unavailable.');
        $this->connections->connection($root->get('connectionId'));
        if($action==='edit'&&!$this->acl->checkField('NexaMessagingConversation','messages','edit'))throw new Forbidden('Messenger reply permission is required.');return $root;
    }
    public function process(stdClass $in): array
    {
        $this->access();if(strlen(json_encode($in,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>12000)throw new BadRequest('Messenger request exceeds its limit.');
        return match($in->op??''){'list'=>$this->listing($in),'thread'=>$this->thread($in),'reply'=>$this->reply($in),'media'=>$this->file($in),'reviewOptIn'=>$this->review($in),'linkContact'=>$this->linkContact($in),default=>throw new BadRequest('Choose a Messenger operation.')};
    }
    private function canLinkContact(Entity $root): bool
    {
        if(!$this->acl->checkEntity($root,'edit')||!$this->acl->checkField('NexaMessagingConversation','messages','edit')||!$this->acl->checkScope('Contact','read')||!$this->acl->checkScope('Contact','edit'))return false;
        foreach(['read','edit'] as $action)if(!$this->acl->checkField('NexaMessagingConversation','contactAssociation',$action))return false;
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))return false;
        return true;
    }
    private function linkContact(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','version','contactId'])||!is_int($in->version??null)||!property_exists($in,'contactId')||!($in->contactId===null||is_string($in->contactId)&&preg_match('/^[A-Za-z0-9]{1,17}$/D',$in->contactId)))throw new BadRequest('Provide a Contact and current conversation revision.');
        $this->root($in->id??null,'edit');
        return $this->store->atomic(function()use($in):array{
            $this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$in->id]);$root=$this->root($in->id,'edit');
            if(!$this->canLinkContact($root))throw new Forbidden('Contact association permission is required.');
            if((int)$root->get('versionNumber')!==$in->version)throw new Conflict('The conversation changed. Refresh before linking a Contact.');
            if($in->contactId!==null){if(!$this->store->rows('SELECT id FROM contact WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE',[...$this->store->scope(),$in->contactId]))throw new NotFound('Contact unavailable.');$contact=$this->records->createForUser('Contact',$this->user)->getEntity($in->contactId);if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,'edit'))throw new NotFound('Contact unavailable.');}
            $previous=$root->get('contactId');if($previous!==$in->contactId){$root->set('contactId',$in->contactId);$this->em->saveEntity($root);$this->connections->audit($root->getId(),'messenger.contact_linked',['previousContactId'=>$previous,'contactId'=>$in->contactId,'identityVerified'=>false,'consentChanged'=>false]);}
            return ['version'=>(int)$root->get('versionNumber')];
        },true);
    }
    public function contactTimeline(string $contactId,int $limit=50): array
    {
        if($this->user->isSystem()||!$this->acl->checkScope('NexaMessagingConversation','read')||!$this->acl->checkScope('Contact','read')||!$this->acl->checkField('NexaMessagingConversation','messages','read')||!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read'))return [];
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))return [];
        $this->access();$contact=$this->records->createForUser('Contact',$this->user)->getEntity($contactId);
        if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,'read'))throw new NotFound('Contact unavailable.');
        $parts=[];$args=[];
        foreach(['incoming','outgoing'] as $direction){
            $table=$direction==='incoming'?'nexa_messenger_event':'nexa_messenger_reply';
            $time=$direction==='incoming'?'r.provider_at':'COALESCE(r.provider_sent_at,r.created_at)';
            $payload=$direction==='incoming'?'r.payload_encrypted':'r.body_encrypted';
            $delivery=$direction==='incoming'?'NULL':'r.delivery_status';
            $accepted=$direction==='incoming'?'':" AND r.status='accepted'";
            $parts[]="SELECT r.id,r.conversation_id,$payload AS encrypted,$time AS created_at,$delivery AS delivery_status,'$direction' AS direction FROM $table r JOIN nexa_messaging_conversation c ON c.id=r.conversation_id AND c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.connection_id=r.connection_id JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.channel='messenger' AND p.provider='meta' WHERE c.tenant_id=? AND c.service_id=? AND c.contact_id=? AND c.deleted=0$accepted";
            $args=array_merge($args,[...$this->store->scope(),$contactId]);
        }
        $rows=$this->store->rows('SELECT * FROM ('.implode(' UNION ALL ',$parts).') history ORDER BY created_at DESC,id DESC LIMIT 200',$args);
        $list=[];$roots=[];
        foreach($rows as $row){
            if(!array_key_exists($row['conversation_id'],$roots)){
                try{$root=$this->root($row['conversation_id']);$roots[$row['conversation_id']]=$root->get('contactId')===$contactId?$root:null;}catch(Forbidden|NotFound $e){$roots[$row['conversation_id']]=null;}
            }
            $root=$roots[$row['conversation_id']];if(!$root)continue;
            try{$context=$row['direction']==='incoming'?'messenger-event:':'messenger-reply:';$body=$this->cipher->decryptFor($row['encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],$context.$row['id']]);if($row['direction']==='incoming')$body=json_decode($body,true,16,JSON_THROW_ON_ERROR)['body']??null;}catch(\Throwable $e){$body=null;}
            $list[]=['id'=>$row['id'],'channel'=>'messenger','direction'=>$row['direction'],'conversationId'=>$root->getId(),'connectionName'=>$root->get('name'),'body'=>$body,'createdAt'=>$row['created_at'],'deliveryStatus'=>$row['delivery_status']];
            if(count($list)>=min(200,max(1,$limit)))break;
        }
        return $list;
    }
    private function listing(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','q','before'])||!is_string($in->q??'')||mb_strlen($in->q??'')>100||!is_string($in->before??'')||(($in->before??'')!==''&&!preg_match('/^[A-Za-z0-9]{1,17}$/D',$in->before)))throw new BadRequest('Choose valid Messenger filters.');
        $rows=$this->store->rows("SELECT c.id FROM nexa_messaging_conversation c JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.channel='messenger' AND p.provider='meta' WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND c.id<? ORDER BY c.id DESC LIMIT 100",[...$this->store->scope(),($in->before??'')?:str_repeat('z',17)]);$list=[];
        foreach($rows as $row){try{$root=$this->root($row['id']);$name=$this->acl->checkField('NexaMessagingConversation','peer','read')?'Page visitor '.$this->peer($root):'Page visitor';if(($in->q??'')!==''&&mb_stripos($name.' '.$root->get('name'),$in->q)===false)continue;$list[]=['id'=>$root->getId(),'name'=>$name,'connectionName'=>$root->get('name'),'status'=>$root->get('status'),'version'=>(int)$root->get('versionNumber')];}catch(Forbidden|NotFound $e){}}
        return ['list'=>$list,'nextCursor'=>count($rows)===100?end($rows)['id']:null,'canConfigure'=>$this->user->isAdmin()];
    }
    private function peer(Entity $root): string {return $this->cipher->decryptFor($root->get('peerEncrypted'),...[$this->store->scope()[0],$this->store->scope()[1],'messaging-peer:'.$root->get('connectionId')]);}
    private function policy(Entity $root): array
    {
        $root=$this->root($root->getId(),'edit');$connection=$this->connections->connection($root->get('connectionId'));if(!$this->connections->enabled($connection))throw new Forbidden('Messenger replies are not activated with fresh Page proof.');
        $event=$this->store->rows('SELECT provider_at,payload_encrypted,id FROM nexa_messenger_event WHERE tenant_id=? AND service_id=? AND conversation_id=? AND connection_id=? ORDER BY provider_at DESC,id DESC LIMIT 1',[...$this->store->scope(),$root->getId(),$connection['id']])[0]??null;
        if(!$event)throw new Forbidden('A verified customer message is required.');
        $payload=json_decode($this->cipher->decryptFor($event['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-event:'.$event['id']]),true,16,JSON_THROW_ON_ERROR);
        if(!MessengerContract::window($payload['timestamp'],time())||$payload['page']!==$connection['sender']||$payload['peer']!==$this->peer($root))throw new Forbidden('The Messenger reply window has closed.');
        if($this->reviews->state($root->getId())['blocked'])throw new Forbidden('This Messenger visitor has opted out.');
        $this->contactAllowed($root);return $connection;
    }
    private function contactAllowed(Entity $root): void
    {
        if($root->get('contactId')){
            if(!$this->acl->checkField('NexaMessagingConversation','contactAssociation','read')||!$this->acl->checkScope('Contact','read'))throw new Forbidden('Linked Contact access is required.');
            $contact=$this->records->createForUser('Contact',$this->user)->getEntity($root->get('contactId'));
            if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,'read')||$contact->get('doNotContact'))throw new Forbidden('Linked Contact restrictions prevent this reply.');
        }
    }
    private function thread(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','before'])||!is_string($in->before??''))throw new BadRequest('Invalid Messenger history request.');$root=$this->root($in->id??null);$args=[...$this->store->scope(),$root->getId()];$filter='';
        if(($in->before??'')!==''){ChatStore::uuidValue($in->before);$anchor=$this->store->rows('SELECT provider_at,id FROM nexa_messenger_event WHERE tenant_id=? AND service_id=? AND conversation_id=? AND id=?',[...$args,$in->before])[0]??null;if(!$anchor)throw new NotFound('Message cursor unavailable.');$filter=' AND (provider_at<? OR (provider_at=? AND id<?))';array_push($args,$anchor['provider_at'],$anchor['provider_at'],$in->before);}
        $events=$this->store->rows('SELECT * FROM nexa_messenger_event WHERE tenant_id=? AND service_id=? AND conversation_id=?'.$filter.' ORDER BY provider_at DESC,id DESC LIMIT 51',$args);$more=count($events)>50;$messages=[];
        foreach(array_reverse(array_slice($events,0,50)) as $row){$p=json_decode($this->cipher->decryptFor($row['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-event:'.$row['id']]),true,16,JSON_THROW_ON_ERROR);$messages[]=['id'=>$row['id'],'body'=>$p['body'],'attachments'=>$p['attachments'],'media'=>$this->media->listing($row['id']),'createdAt'=>$row['provider_at']];}
        $outgoing=[];foreach($this->store->rows('SELECT * FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND conversation_id=? ORDER BY created_at DESC,id DESC LIMIT 50',[...$this->store->scope(),$root->getId()]) as $row){$outgoing[]=['id'=>$row['id'],'body'=>$this->cipher->decryptFor($row['body_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-reply:'.$row['id']]),'status'=>$row['status'],'deliveryStatus'=>$row['delivery_status'],'attachmentId'=>$row['attachment_id'],'mediaType'=>$row['media_type'],'createdAt'=>$row['created_at']];}
        try{$this->policy($root);$canReply=true;$reason='';}catch(Forbidden|NotFound $e){$canReply=false;$reason='Replies are unavailable under the current window, restrictions, permissions or connection settings.';}
        $review=$this->reviews->state($root->getId(),$this->user->isAdmin());try{$this->root($root->getId(),'edit');$this->contactAllowed($root);}catch(Forbidden|NotFound $e){$review['canReview']=false;}
        $contact=null;
        if($root->get('contactId')&&$this->acl->checkField('NexaMessagingConversation','contactAssociation','read')&&$this->acl->checkScope('Contact','read')){
            try{$item=$this->records->createForUser('Contact',$this->user)->getEntity($root->get('contactId'));$visible=true;foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))$visible=false;if($item&&$visible&&[$item->get('tenantId'),$item->get('serviceId')]===$this->store->scope())$contact=['id'=>$item->getId(),'name'=>$item->get('name')];}catch(Forbidden|NotFound $e){}
        }
        return ['conversation'=>['id'=>$root->getId(),'name'=>$root->get('name'),'status'=>$root->get('status'),'version'=>(int)$root->get('versionNumber')],'messages'=>$messages,'outgoing'=>$outgoing,'hasMore'=>$more,'canReply'=>$canReply,'reason'=>$reason,'optInReview'=>$review,'contact'=>$contact,'canLinkContact'=>$this->canLinkContact($root)];
    }
    private function reply(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','version','body','requestKey','attachmentId'])||!is_int($in->version??null)||!is_string($in->body??null))throw new BadRequest('Provide the current conversation revision and reply.');
        $this->root($in->id??null,'edit');$media=null;
        if(($in->attachmentId??null)!==null){if(!is_string($in->attachmentId)||($in->body??'')!=='')throw new BadRequest('Send an attachment or text as separate replies.');$media=$this->media->outgoing($in->attachmentId,$this->acl);}else MessengerContract::text($in->body??null);
        ChatStore::uuidValue($in->requestKey??null);
        $claim=$this->store->atomic(function()use($in,$media):array{
            $this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$in->id]);$root=$this->root($in->id,'edit');if((int)$root->get('versionNumber')!==$in->version)throw new Conflict('The conversation changed.');
            $hash=hash('sha256',json_encode($media?[$in->id,'media',$media['attachmentId'],$media['sha256']]:[$in->id,$in->body],JSON_THROW_ON_ERROR));$old=$this->store->rows('SELECT id,intent_hash,status FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND request_key=?',[...$this->store->scope(),$in->requestKey])[0]??null;
            if($old){if(!hash_equals($old['intent_hash'],$hash))throw new Conflict('This reply key was already used.');return ['duplicate'=>true,'id'=>$old['id'],'status'=>$old['status']];}
            $connection=$this->policy($root);$quota=$this->store->rows('SELECT SUM(created_at>=UTC_DATE()) AS day_count,SUM(created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)) AS minute_count FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND created_at>=LEAST(UTC_DATE(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))',$this->store->scope())[0];
            if((int)$quota['day_count']>=1000||(int)$quota['minute_count']>=5)throw new BadRequest('The workspace Messenger reply limit has been reached.');
            $id=ChatStore::uuid();$encrypted=$this->cipher->encryptFor($in->body,...[$this->store->scope()[0],$this->store->scope()[1],'messenger-reply:'.$id]);
            $this->store->execute('INSERT INTO nexa_messenger_reply (id,tenant_id,service_id,connection_id,conversation_id,created_by_id,request_key,intent_hash,body_encrypted,created_at,modified_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$id,...$this->store->scope(),$connection['id'],$root->getId(),$this->user->getId(),$in->requestKey,$hash,$encrypted]);
            if($media){
                if(!$this->store->rows('SELECT id FROM attachment WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 AND is_being_uploaded=0 FOR UPDATE',[...$this->store->scope(),$media['attachmentId']]))throw new NotFound('The selected file changed.');
                $this->store->execute('UPDATE nexa_messenger_reply SET attachment_id=?,media_type=?,media_sha256=? WHERE tenant_id=? AND service_id=? AND id=?',[$media['attachmentId'],$media['kind'],$media['sha256'],...$this->store->scope(),$id]);
            }
            $this->connections->audit($id,'messenger.reply_claimed',['conversationId'=>$root->getId()]);return ['id'=>$id,'connection'=>$connection,'peer'=>$this->peer($root)];
        },true);
        if($claim['duplicate']??false)return $claim;
        try{[$token,$secret]=$this->connections->secrets($claim['connection']);$result=$this->client->sendTracked($claim['connection']['sender'],$claim['peer'],$in->body,$claim['id'],$token,$secret,$media);}catch(\Throwable $e){$result=['status'=>'uncertain'];}
        return $this->store->atomic(function()use($claim,$result):array{
            $this->store->execute("UPDATE nexa_messenger_reply SET status=?,provider_message_id=?,delivery_status=IF(?='accepted','accepted',delivery_status),modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=? AND status IN ('submitting','uncertain')",[$result['status'],$result['messageId']??null,$result['status'],...$this->store->scope(),$claim['id']]);
            $this->receipts->reconcile($claim['connection']['id'],hash('sha256',$claim['connection']['id']."\0".$claim['peer']));
            $state=$this->store->rows('SELECT status FROM nexa_messenger_reply WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$claim['id']])[0]['status'];$this->connections->audit($claim['id'],'messenger.reply_'.$state,[]);return ['id'=>$claim['id'],'status'=>$state];
        },true);
    }
    private function file(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','mediaId']))throw new BadRequest('Invalid media request.');$this->root($in->id??null);ChatStore::uuidValue($in->mediaId??null);return $this->media->file($in->id,$in->mediaId);
    }
    private function review(stdClass $in): array
    {
        $this->root($in->id??null,'edit');
        return $this->store->atomic(function()use($in):array{
            $this->store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$in->id]);$root=$this->root($in->id,'edit');$this->contactAllowed($root);return $this->reviews->release($root->getId(),$in,$this->user);
        },true);
    }
}
