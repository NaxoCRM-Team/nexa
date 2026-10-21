<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\{Acl,ORM\EntityManager};
use Espo\Core\Exceptions\{Forbidden,NotFound};
use Espo\Core\FileStorage\Manager as FileStorage;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\{Attachment,User};

/** Signed receipt metadata is indexed separately from bounded, retryable private file IO. */
final class IncomingMediaService
{
    public function __construct(private ChatStore $store,private EntityManager $em,private User $user,private FileStorage $storage,private ProviderSecretCipher $cipher,private TwilioMediaFetcher $fetcher,private ?string $storageName=null) {}
    public function admit(string $receipt,string $conversation,array $items): void
    {
        if(!$this->em->getPDO()->inTransaction())throw new \LogicException('Media indexing requires the receipt transaction.');
        $count=(int)$this->store->rows("SELECT COUNT(*) n FROM nexa_messaging_media WHERE tenant_id=? AND service_id=? AND status IN ('pending','downloading')",$this->store->scope())[0]['n'];
        foreach($items as $slot=>$item){
            if($this->store->rows('SELECT id FROM nexa_messaging_media WHERE tenant_id=? AND service_id=? AND receipt_id=? AND slot=?',[...$this->store->scope(),$receipt,$slot]))continue;
            $id=ChatStore::uuid();$full=$count++>=1000;
            $this->store->execute('INSERT INTO nexa_messaging_media (id,tenant_id,service_id,receipt_id,conversation_id,slot,content_type,source_encrypted,status,last_error) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,...$this->store->scope(),$receipt,$conversation,$slot,$item['contentType'],$this->cipher->encryptFor($item['url'],...[$this->store->scope()[0],$this->store->scope()[1],'incoming-media:'.$id]),$full?'failed':'pending',$full?'workspace_capacity':null]);
        }
        $this->store->execute('UPDATE nexa_messaging_receipt SET media_indexed=1,media_index_error=NULL WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$receipt]);
    }
    public function listing(string $receipt): array
    {
        return $this->store->rows('SELECT m.id,m.content_type AS mimeType,m.status,m.last_error AS error,a.name,a.size FROM nexa_messaging_media m LEFT JOIN attachment a ON a.id=m.attachment_id AND a.tenant_id=m.tenant_id AND a.service_id=m.service_id AND a.deleted=0 WHERE m.tenant_id=? AND m.service_id=? AND m.receipt_id=? ORDER BY m.slot',[...$this->store->scope(),$receipt]);
    }
    public function file(string $conversation,string $id,Acl $acl): array
    {
        ChatStore::uuidValue($id);
        $row=$this->store->rows("SELECT attachment_id FROM nexa_messaging_media WHERE tenant_id=? AND service_id=? AND conversation_id=? AND id=? AND status='ready'",[...$this->store->scope(),$conversation,$id])[0]??null;
        $file=$row?$this->em->getEntityById('Attachment',$row['attachment_id']):null;
        if(!$file||$file->get('isBeingUploaded')||[$file->get('tenantId'),$file->get('serviceId')]!==$this->store->scope()||!$acl->checkEntity($file,'read'))throw new NotFound('The attachment is unavailable.');
        return ['name'=>$file->get('name'),'mimeType'=>$file->get('type'),'data'=>base64_encode($this->storage->getContents($file))];
    }
    private function active(): bool
    {
        return (bool)$this->store->rows("SELECT ts.tenant_id FROM nexa_tenant_service ts JOIN nexa_tenant t ON t.id=ts.tenant_id AND t.status='active' JOIN nexa_service_definition d ON d.id=ts.service_id AND d.status='active' WHERE ts.tenant_id=? AND ts.service_id=? AND ts.status='active'",$this->store->scope());
    }
    private function indexHistory(): void
    {
        $rows=$this->store->rows("SELECT r.id FROM nexa_messaging_receipt r JOIN nexa_messaging_conversation c ON c.id=r.conversation_id AND c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.connection_id=r.connection_id AND c.deleted=0 JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.provider='twilio' AND p.status='verified' WHERE r.tenant_id=? AND r.service_id=? AND r.kind='inbound' AND r.media_indexed=0 AND (r.media_index_retry_at IS NULL OR r.media_index_retry_at<=UTC_TIMESTAMP()) ORDER BY r.received_at,r.id LIMIT 100",$this->store->scope());
        foreach($rows as $candidate)$this->store->atomic(function()use($candidate):void{
            $r=$this->store->rows("SELECT r.* FROM nexa_messaging_receipt r JOIN nexa_messaging_conversation c ON c.id=r.conversation_id AND c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.deleted=0 WHERE r.tenant_id=? AND r.service_id=? AND r.id=? AND r.media_indexed=0 FOR UPDATE",[...$this->store->scope(),$candidate['id']])[0]??null;if(!$r)return;
            try{$event=json_decode($this->cipher->decryptFor($r['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging-receipt:'.$r['id']]),true,32,JSON_THROW_ON_ERROR);if(!is_array($event['media']??null)||count($event['media'])>10)throw new \RuntimeException('Invalid media history.');$this->admit($r['id'],$r['conversation_id'],$event['media']);}
            catch(\Throwable $error){$this->store->execute("UPDATE nexa_messaging_receipt SET media_index_attempts=media_index_attempts+1,media_indexed=?,media_index_retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),media_index_error='media_index_failed' WHERE tenant_id=? AND service_id=? AND id=?",[(int)$r['media_index_attempts']>=2?1:0,...$this->store->scope(),$r['id']]);}
        },true);
    }
    private function claim(string $id): ?array
    {
        return $this->store->atomic(function()use($id):?array{
            if(!$this->active())return null;
            $r=$this->store->rows("SELECT m.*,p.account_sid,p.secret_encrypted,p.version_number AS connection_version,r.provider_message_id FROM nexa_messaging_media m JOIN nexa_messaging_receipt r ON r.id=m.receipt_id AND r.tenant_id=m.tenant_id AND r.service_id=m.service_id JOIN nexa_messaging_conversation c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id AND c.connection_id=r.connection_id AND c.deleted=0 JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.provider='twilio' AND p.channel IN ('sms','whatsapp') AND p.status='verified' WHERE m.tenant_id=? AND m.service_id=? AND m.id=? AND m.status='pending' AND m.attempts<3 AND (m.retry_at IS NULL OR m.retry_at<=UTC_TIMESTAMP()) FOR UPDATE",[...$this->store->scope(),$id])[0]??null;
            if(!$r)return null;$r['claim_token']=ChatStore::uuid();$this->store->execute("UPDATE nexa_messaging_media SET status='downloading',claim_token=?,attempts=attempts+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[$r['claim_token'],...$this->store->scope(),$id]);
            $r['connection_id']=$this->store->rows('SELECT connection_id FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$r['receipt_id']])[0]['connection_id'];return $r;
        },true);
    }
    private function current(array $r): bool
    {
        return $this->active()&&(bool)$this->store->rows("SELECT m.id FROM nexa_messaging_media m JOIN nexa_messaging_conversation c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id AND c.deleted=0 JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.id=? AND p.provider='twilio' AND p.status='verified' AND p.version_number=? WHERE m.tenant_id=? AND m.service_id=? AND m.id=? AND m.status='downloading' AND m.claim_token=? FOR UPDATE",[$r['connection_id'],$r['connection_version'],...$this->store->scope(),$r['id'],$r['claim_token']]);
    }
    public function recover(?string $onlyId=null): int
    {
        if(!$this->user->isSystem())throw new Forbidden('Incoming attachment recovery is a background operation.');
        if(!$this->active())return 0;if($onlyId!==null)ChatStore::uuidValue($onlyId);
        $this->cleanupStaging();$this->indexHistory();
        $this->store->atomic(fn()=>$this->store->execute("UPDATE nexa_messaging_media SET status=IF(attempts>=3,'failed','pending'),claim_token=NULL,retry_at=UTC_TIMESTAMP(),last_error='interrupted_download',modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND status='downloading' AND modified_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)",$this->store->scope()),true);
        $rows=$this->store->rows("SELECT m.id FROM nexa_messaging_media m JOIN nexa_messaging_conversation c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id AND c.service_id=m.service_id AND c.deleted=0 JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.provider='twilio' AND p.status='verified' WHERE m.tenant_id=? AND m.service_id=? AND m.status='pending' AND (m.retry_at IS NULL OR m.retry_at<=UTC_TIMESTAMP())".($onlyId!==null?' AND m.id=?':'').' ORDER BY COALESCE(m.retry_at,m.created_at),m.id LIMIT 2',$onlyId!==null?[...$this->store->scope(),$onlyId]:$this->store->scope());$done=0;
        foreach($rows as $candidate){$r=$this->claim($candidate['id']);if(!$r)continue;
            try{
                $url=$this->cipher->decryptFor($r['source_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'incoming-media:'.$r['id']]);
                TwilioMediaFetcher::url($url,$r['account_sid'],$r['provider_message_id']);
                $secret=$this->cipher->decryptFor($r['secret_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging:'.$r['connection_id']]);
                $bytes=$this->fetcher->fetch($url,$r['account_sid'],$r['provider_message_id'],$secret);$info=TwilioMediaFetcher::inspect($bytes,$r['content_type']);
                $file=$this->store->atomic(function()use($r,$info):?Attachment{
                    if(!$this->current($r))return null;
                    // Each attempt owns a new, hidden native file; stale uploads cannot overwrite a winner.
                    $file=$this->em->getNewEntity('Attachment');$file->set(['name'=>$info['name'],'type'=>$info['mimeType'],'size'=>$info['size'],'role'=>Attachment::ROLE_ATTACHMENT,'field'=>'nexaIncomingMediaStaging','parentType'=>'NexaMessagingConversation','parentId'=>$r['conversation_id'],'global'=>false,'deleted'=>true,'isBeingUploaded'=>false]);if($this->storageName)$file->set('storage',$this->storageName);
                    $this->em->saveEntity($file,['silent'=>true,'skipCreatedBy'=>true,'skipModifiedBy'=>true]);
                    $this->store->execute('UPDATE nexa_messaging_media SET attachment_id=? WHERE tenant_id=? AND service_id=? AND id=?',[$file->getId(),...$this->store->scope(),$r['id']]);return $file;
                },true);if(!$file)throw new \RuntimeException('Media claim changed.');
                // Both provider fetch and configured-storage IO happen outside admission locks.
                $this->storage->putContents($file,$bytes);
                $published=$this->store->atomic(function()use($r,$file):bool{
                    if(!$this->current($r))return false;
                    $this->store->execute("UPDATE attachment SET deleted=0,field='messages' WHERE tenant_id=? AND service_id=? AND id=? AND parent_type=? AND parent_id=?",[...$this->store->scope(),$file->getId(),'NexaMessagingConversation',$r['conversation_id']]);
                    $this->store->execute("UPDATE nexa_messaging_media SET status='ready',claim_token=NULL,last_error=NULL,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$r['id']]);return true;
                },true);if(!$published)throw new \RuntimeException('Media publication cancelled.');$done++;
            }catch(\Throwable $error){$this->store->atomic(fn()=>$this->store->execute("UPDATE nexa_messaging_media SET status=IF(attempts>=3,'failed','pending'),claim_token=NULL,last_error='media_unavailable',retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=? AND claim_token=? AND status='downloading'",[...$this->store->scope(),$r['id'],$r['claim_token']]),true);}
        }
        return $done;
    }
    private function cleanupStaging(): void
    {
        $query=$this->em->getQueryBuilder()->select()->from('Attachment')->withDeleted()->where(['deleted'=>true,'field'=>'nexaIncomingMediaStaging','parentType'=>'NexaMessagingConversation','createdAt<'=>gmdate('Y-m-d H:i:s',time()-86400)])->order('createdAt')->limit(0,2)->build();
        foreach($this->em->getRDBRepository('Attachment')->clone($query)->find() as $file){
            if($this->store->rows("SELECT id FROM nexa_messaging_media WHERE tenant_id=? AND service_id=? AND attachment_id=? AND status='downloading' AND modified_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)",[...$this->store->scope(),$file->getId()]))continue;
            try{
                if($this->storage->exists($file))$this->storage->unlink($file);
                $this->store->execute("DELETE FROM attachment WHERE tenant_id=? AND service_id=? AND id=? AND deleted=1 AND field='nexaIncomingMediaStaging'",[...$this->store->scope(),$file->getId()]);
            }catch(\Throwable $error){/* Keep scoped metadata for a later storage-cleanup retry. */}
        }
    }
}
