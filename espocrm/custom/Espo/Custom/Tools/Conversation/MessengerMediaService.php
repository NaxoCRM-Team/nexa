<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\{Acl,InjectableFactory};
use Espo\Core\ORM\EntityManager;
use Espo\Core\FileStorage\Manager as FileStorage;
use Espo\Core\Exceptions\{NotFound,Forbidden};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\Attachment;

final class MessengerMediaService
{
    public function __construct(private ChatStore $store,private EntityManager $em,private Acl $acl,private FileStorage $storage,private ProviderSecretCipher $cipher,private MessengerMediaFetcher $fetcher,private ?string $storageName=null) {}
    public function admit(string $event,string $conversation,array $media): void
    {
        if(!$this->em->getPDO()->inTransaction())throw new \LogicException('Media admission requires the signed receipt transaction.');
        $pending=count($this->store->rows("SELECT id FROM nexa_messenger_media WHERE tenant_id=? AND service_id=? AND status IN ('pending','downloading') LIMIT 1000",$this->store->scope()));
        foreach($media as $slot=>$item){$id=ChatStore::uuid();$source=$this->cipher->encryptFor($item['url'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-media:'.$id]);
            $full=$pending++>=1000;$this->store->execute('INSERT INTO nexa_messenger_media (id,tenant_id,service_id,event_id,conversation_id,slot,media_type,source_encrypted,status,last_error) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,...$this->store->scope(),$event,$conversation,$slot,$item['type'],$source,$full?'failed':'pending',$full?'workspace_capacity':null]);}
    }
    public function outgoing(string $id,?Acl $actorAcl=null): array
    {
        $attachment=$this->em->getEntityById('Attachment',$id);
        if(!$attachment||[$attachment->get('tenantId'),$attachment->get('serviceId')]!==$this->store->scope()||$attachment->get('isBeingUploaded')||!($actorAcl??$this->acl)->checkEntity($attachment,'read'))throw new NotFound('Choose a readable tenant-library file.');
        $libraryFile=$attachment->get('role')===Attachment::ROLE_INLINE_ATTACHMENT&&in_array($attachment->get('field'),['nexaTenantFile','nexaTenantAsset'],true);
        $conversationFile=$attachment->get('role')===Attachment::ROLE_ATTACHMENT&&$attachment->get('parentType')==='NexaMessagingConversation'&&$attachment->get('field')==='messages';
        if(!$libraryFile&&!$conversationFile)throw new NotFound('Choose a readable tenant-library file.');
        if((int)$attachment->get('size')>MessengerMediaFetcher::MAX_BYTES)throw new Forbidden('Messenger files must be 8 MB or smaller.');
        $bytes=$this->storage->getContents($attachment);$mime=(string)$attachment->get('type');$kind=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'audio/')?'audio':(str_starts_with($mime,'video/')?'video':'file'));
        return MessengerMediaFetcher::inspect($bytes,$kind)+['attachmentId'=>$id,'bytes'=>$bytes];
    }
    public function listing(string $event): array
    {
        return $this->store->rows('SELECT id,media_type AS type,status,attachment_id AS attachmentId,last_error AS error FROM nexa_messenger_media WHERE tenant_id=? AND service_id=? AND event_id=? ORDER BY slot',[...$this->store->scope(),$event]);
    }
    public function file(string $conversation,string $id): array
    {
        $row=$this->store->rows("SELECT attachment_id FROM nexa_messenger_media WHERE tenant_id=? AND service_id=? AND conversation_id=? AND id=? AND status='ready'",[...$this->store->scope(),$conversation,$id])[0]??null;
        if(!$row)throw new NotFound('Messenger media is unavailable.');$attachment=$this->em->getEntityById('Attachment',$row['attachment_id']);
        if(!$attachment||[$attachment->get('tenantId'),$attachment->get('serviceId')]!==$this->store->scope())throw new NotFound('Media is unavailable.');
        return ['name'=>$attachment->get('name'),'mimeType'=>$attachment->get('type'),'data'=>base64_encode($this->storage->getContents($attachment))];
    }
    public function recover(?string $onlyId=null): void
    {
        $this->store->atomic(fn()=>$this->store->execute("UPDATE nexa_messenger_media SET status='pending',retry_at=UTC_TIMESTAMP(),modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND status='downloading' AND modified_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)",$this->store->scope()),true);
        if($onlyId!==null)ChatStore::uuidValue($onlyId);
        $rows=$this->store->rows("SELECT id FROM nexa_messenger_media WHERE tenant_id=? AND service_id=? AND status='pending' AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP())".($onlyId!==null?' AND id=?':'').' ORDER BY created_at,id LIMIT 2',$onlyId!==null?[...$this->store->scope(),$onlyId]:$this->store->scope());
        foreach($rows as $item){$row=$this->store->atomic(function()use($item):?array{
            $r=$this->store->rows("SELECT m.* FROM nexa_messenger_media m JOIN nexa_messaging_conversation c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id AND c.deleted=0 JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.status='verified' AND p.provider='meta' JOIN nexa_tenant t ON t.id=m.tenant_id AND t.status='active' JOIN nexa_tenant_service ts ON ts.tenant_id=m.tenant_id AND ts.service_id=m.service_id AND ts.status='active' JOIN nexa_service_definition d ON d.id=m.service_id AND d.status='active' WHERE m.tenant_id=? AND m.service_id=? AND m.id=? AND m.status='pending' FOR UPDATE",[...$this->store->scope(),$item['id']])[0]??null;if(!$r)return null;
            $this->store->execute("UPDATE nexa_messenger_media SET status='downloading',attempts=attempts+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$r['id']]);return $r;
        },true);if(!$row)continue;
            try{
                $url=$this->cipher->decryptFor($row['source_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-media:'.$row['id']]);$bytes=$this->fetcher->fetch($url);$info=MessengerMediaFetcher::inspect($bytes,$row['media_type']);
                $this->store->atomic(function()use($row,$bytes,$info):void{
                    $current=$this->store->rows("SELECT m.id FROM nexa_messenger_media m JOIN nexa_messaging_conversation c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id AND c.deleted=0 WHERE m.tenant_id=? AND m.service_id=? AND m.id=? AND m.status='downloading' AND m.attempts=? FOR UPDATE",[...$this->store->scope(),$row['id'],(int)$row['attempts']+1]);if(!$current)return;
                    $attachment=$this->em->getNewEntity('Attachment');$attachment->set(['name'=>$info['name'],'type'=>$info['mimeType'],'size'=>$info['size'],'role'=>Attachment::ROLE_ATTACHMENT,'field'=>'messages','parentType'=>'NexaMessagingConversation','parentId'=>$row['conversation_id'],'global'=>false]);if($this->storageName)$attachment->set('storage',$this->storageName);$attachment->setContents($bytes);$this->em->saveEntity($attachment,['silent'=>true,'skipCreatedBy'=>true,'skipModifiedBy'=>true]);
                    $this->store->execute("UPDATE nexa_messenger_media SET status='ready',attachment_id=?,last_error=NULL,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[$attachment->getId(),...$this->store->scope(),$row['id']]);
                },true);
            }catch(\Throwable $error){$this->store->atomic(fn()=>$this->store->execute("UPDATE nexa_messenger_media SET status=?,last_error='media_unavailable',retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=? AND status='downloading' AND attempts=?",[(int)$row['attempts']>=2?'failed':'pending',...$this->store->scope(),$row['id'],(int)$row['attempts']+1]),true);}
        }
    }
}
