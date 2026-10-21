<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Acl;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Core\FileStorage\Manager as FileStorage;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

/** Closing, recoverable deletion and irreversible erasure are distinct operations. */
final class ChatPrivacyService
{
    public function __construct(private ChatStore $store,private EntityManager $em,private User $user,private Acl $acl,private ServiceFactory $records,private FileStorage $storage) {}
    private function active(): bool
    {
        return (bool)$this->store->rows("SELECT ts.tenant_id FROM nexa_tenant_service ts JOIN nexa_tenant t ON t.id=ts.tenant_id AND t.status='active' JOIN nexa_service_definition d ON d.id=ts.service_id AND d.status='active' WHERE ts.tenant_id=? AND ts.service_id=? AND ts.status='active'",$this->store->scope());
    }
    private function staff(bool $admin=false): void
    {
        if(!$this->active()||!in_array($this->user->getType(),['admin','regular'],true)||[$this->user->get('tenantId'),$this->user->get('serviceId')]!==$this->store->scope()||!$this->store->rows("SELECT id FROM user WHERE tenant_id=? AND service_id=? AND id=? AND type=? AND is_active=1 AND deleted=0",[...$this->store->scope(),$this->user->getId(),$this->user->getType()])||($admin&&!$this->user->isAdmin()))throw new Forbidden('Active tenant administrator access is required.');
    }
    private function policy(): array
    {
        $row=$this->store->rows('SELECT * FROM nexa_chat_privacy_policy WHERE tenant_id=? AND service_id=?',$this->store->scope())[0]??[];
        return ['retentionDays'=>(int)($row['retention_days']??90),'legalHold'=>(bool)($row['legal_hold']??false),'holdReason'=>$row['hold_reason']??'','version'=>(int)($row['version_number']??0)];
    }
    public function process(stdClass $in): array
    {
        $this->staff();if(strlen(json_encode($in,JSON_THROW_ON_ERROR))>6000)throw new BadRequest('Privacy request is too large.');
        return match($in->op??''){'workspace'=>$this->workspace($in),'save'=>$this->save($in),'delete'=>$this->softDelete($in),'restore'=>$this->restore($in),'erase'=>$this->erase($in),default=>throw new BadRequest('Choose a chat privacy operation.')};
    }
    private function workspace(stdClass $in): array
    {
        $this->staff(true);if(array_diff(array_keys((array)$in),['op','q','before'])||!is_string($in->q??'')||mb_strlen($in->q??'')>100||!is_string($in->before??'')||(($in->before??'')!==''&&!preg_match('/^[A-Za-z0-9]{1,17}$/D',$in->before)))throw new BadRequest('Choose valid deleted-chat filters.');
        $pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],trim($in->q??'')).'%';
        $rows=$this->store->rows("SELECT id,name,privacy_deleted_at AS deletedAt,DATE_ADD(privacy_deleted_at,INTERVAL 2 MONTH) AS restoreUntil,version_number AS version FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND deleted=1 AND privacy_erasing_at IS NULL AND id<? AND COALESCE(name,'') LIKE ? ORDER BY id DESC LIMIT 51",[...$this->store->scope(),($in->before??'')?:str_repeat('z',17),$pattern]);$more=count($rows)>50;$rows=array_slice($rows,0,50);
        foreach($rows as &$row)$row['version']=(int)$row['version'];unset($row);
        $pending=$this->store->rows('SELECT COUNT(*) total FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND completed_at IS NULL',$this->store->scope())[0]['total'];
        return ['policy'=>$this->policy(),'list'=>$rows,'nextCursor'=>$more?end($rows)['id']:null,'pendingErasure'=>(int)$pending];
    }
    private function save(stdClass $in): array
    {
        $this->staff(true);if(array_diff(array_keys((array)$in),['op','retentionDays','legalHold','holdReason','version'])||!is_int($in->retentionDays??null)||$in->retentionDays<1||$in->retentionDays>3650||!is_bool($in->legalHold??null)||!is_string($in->holdReason??null)||mb_strlen($in->holdReason)>500||($in->legalHold&&mb_strlen(trim($in->holdReason))<10)||!is_int($in->version??null))throw new BadRequest('Choose 1-3,650 retention days and provide a legal-hold reason when applicable.');
        return $this->store->atomic(function()use($in):array{
            $this->staff(true);$old=$this->policy();if($old['version']!==$in->version)throw new Conflict('Privacy settings changed. Refresh before saving.');
            $this->store->execute('INSERT INTO nexa_chat_privacy_policy (tenant_id,service_id,retention_days,legal_hold,hold_reason) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE retention_days=VALUES(retention_days),legal_hold=VALUES(legal_hold),hold_reason=VALUES(hold_reason),version_number=version_number+1,modified_at=UTC_TIMESTAMP()',[...$this->store->scope(),$in->retentionDays,(int)$in->legalHold,$in->legalHold?trim($in->holdReason):'']);
            $this->audit('chat.privacy_policy_updated','policy',['retentionDays'=>$in->retentionDays,'legalHold'=>$in->legalHold]);return ['policy'=>$this->policy()];
        },true);
    }
    private function id(mixed $id): string { if(!is_string($id)||!preg_match('/^[A-Za-z0-9]{1,17}$/D',$id))throw new NotFound('Chat unavailable.');return $id; }
    private function action(stdClass $in,bool $permanent=false): string
    {
        $allowed=$permanent?['op','id','version','confirmation','reason']:['op','id','version'];
        if(array_diff(array_keys((array)$in),$allowed)||!is_int($in->version??null))throw new BadRequest('Provide the current chat revision.');
        if($permanent&&(($in->confirmation??null)!=='DELETE'||!is_string($in->reason??null)||mb_strlen(trim($in->reason))<10||mb_strlen($in->reason)>500))throw new BadRequest('Type DELETE and provide an erasure reason of 10-500 characters.');
        return $this->id($in->id??null);
    }
    private function softDelete(stdClass $in): array
    {
        $id=$this->action($in);return $this->store->atomic(function()use($id,$in):array{
            $this->staff();$this->store->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$id]);
            $root=$this->records->createForUser('NexaChatConversation',$this->user)->getEntity($id);
            if(!$root||[$root->get('tenantId'),$root->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($root,'delete')||!$this->acl->checkField('NexaChatConversation','messages','read'))throw new Forbidden('Chat deletion permission is required.');
            if((int)$root->get('versionNumber')!==$in->version)throw new Conflict('The chat changed. Refresh before deleting.');
            $this->em->removeEntity($root);$this->audit('chat.soft_deleted',$id,[]);return ['deleted'=>true];
        },true);
    }
    /** Also used by the native beforeRemove hook, so native DELETE cannot skip revocation. */
    public function markNativeDeletion(Entity $root): void
    {
        if($root->getEntityType()!=='NexaChatConversation'||[$root->get('tenantId'),$root->get('serviceId')]!==$this->store->scope())throw new Forbidden('Chat scope mismatch.');
        $this->store->execute("UPDATE nexa_chat_conversation SET privacy_deleted_at=COALESCE(privacy_deleted_at,UTC_TIMESTAMP()),expires_at=LEAST(expires_at,UTC_TIMESTAMP()),token_hash=?,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[str_repeat('0',64),...$this->store->scope(),$root->getId()]);
    }
    private function restore(stdClass $in): array
    {
        $this->staff(true);$id=$this->action($in);return $this->store->atomic(function()use($id,$in):array{
            $this->staff(true);$row=$this->store->rows('SELECT *,DATE_ADD(privacy_deleted_at,INTERVAL 2 MONTH)>UTC_TIMESTAMP() AS restorable FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND id=? AND deleted=1 AND privacy_erasing_at IS NULL FOR UPDATE',[...$this->store->scope(),$id])[0]??null;
            if(!$row||!$row['restorable'])throw new NotFound('The chat is no longer restorable.');if((int)$row['version_number']!==$in->version)throw new Conflict('The chat changed.');
            $this->store->execute("UPDATE nexa_chat_conversation SET deleted=0,status='Closed',privacy_deleted_at=NULL,expires_at=LEAST(expires_at,UTC_TIMESTAMP()),token_hash=?,modified_at=UTC_TIMESTAMP(),version_number=COALESCE(version_number,0)+1 WHERE tenant_id=? AND service_id=? AND id=?",[str_repeat('0',64),...$this->store->scope(),$id]);
            $this->audit('chat.restored',$id,[]);return ['restored'=>true];
        },true);
    }
    private function erase(stdClass $in): array
    {
        $this->staff(true);$id=$this->action($in,true);$this->stage($id,'manual',hash('sha256',trim($in->reason)),$in->version);
        $this->finish($id);$done=$this->store->rows('SELECT completed_at FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND conversation_id=?',[...$this->store->scope(),$id])[0]??null;
        return ['status'=>($done['completed_at']??null)?'completed':'pending'];
    }
    private function stage(string $id,string $source,?string $reasonHash=null,?int $version=null): array
    {
        return $this->store->atomic(function()use($id,$source,$reasonHash,$version):array{
            if($source==='manual')$this->staff(true);
            if(!$this->active())throw new Forbidden('Workspace is inactive.');if($this->policy()['legalHold'])throw new Forbidden('Legal hold prevents permanent erasure.');
            $old=$this->store->rows('SELECT completed_at FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND conversation_id=? FOR UPDATE',[...$this->store->scope(),$id])[0]??null;if($old)return $old;
            $row=$this->store->rows('SELECT * FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$id])[0]??null;if(!$row)throw new NotFound('Chat unavailable.');
            if($version!==null&&(int)$row['version_number']!==$version)throw new Conflict('The chat changed.');
            if($source==='retention'&&!$this->eligible($row))return [];
            $hash=hash('sha256',implode("\0",[...$this->store->scope(),$row['widget_id'],$row['start_nonce']]));
            $this->store->execute('INSERT INTO nexa_chat_erasure (tenant_id,service_id,conversation_id,widget_id,nonce_hash) VALUES (?,?,?,?,?)',[...$this->store->scope(),$id,$row['widget_id'],$hash]);
            $messages=(int)$this->store->rows('SELECT COUNT(*) total FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=?',[...$this->store->scope(),$id])[0]['total'];
            $this->store->execute('DELETE FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=?',[...$this->store->scope(),$id]);
            $this->store->execute("UPDATE nexa_chat_conversation SET deleted=1,name='Erased chat',origin='',token_hash=?,visitor_key_hash=?,visitor_identity_id=NULL,identity_issuer_id=NULL,bot_snapshot=NULL,bot_receipts=NULL,bot_step=NULL,bot_state='completed',assigned_user_id=NULL,created_by_id=NULL,modified_by_id=NULL,routing_team_id=NULL,privacy_deleted_at=COALESCE(privacy_deleted_at,UTC_TIMESTAMP()),privacy_erasing_at=UTC_TIMESTAMP(),expires_at=UTC_TIMESTAMP(),version_number=COALESCE(version_number,0)+1 WHERE tenant_id=? AND service_id=? AND id=?",[str_repeat('0',64),str_repeat('0',64),...$this->store->scope(),$id]);
            $this->store->execute("UPDATE attachment SET deleted=1 WHERE tenant_id=? AND service_id=? AND ((parent_type='NexaChatConversation' AND parent_id=?) OR (related_type='NexaChatConversation' AND related_id=?))",[...$this->store->scope(),$id,$id]);
            $this->store->execute("DELETE FROM notification WHERE tenant_id=? AND service_id=? AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.entityType'))='NexaChatConversation' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.entityId'))=?",[...$this->store->scope(),$id]);
            $this->audit('chat.erasure_started',$id,['source'=>$source,'messageCount'=>$messages,'reasonHash'=>$reasonHash]);return ['completed_at'=>null];
        },true);
    }
    private function eligible(array $row): bool
    {
        $sql='SELECT id FROM nexa_chat_conversation c WHERE c.tenant_id=? AND c.service_id=? AND c.id=? AND '.$this->eligibility();
        return (bool)$this->store->rows($sql,[...$this->store->scope(),$row['id'],$this->policy()['retentionDays']]);
    }
    private function eligibility(): string
    {
        return "c.privacy_erasing_at IS NULL AND ((c.deleted=1 AND COALESCE(c.privacy_deleted_at,c.modified_at,c.created_at)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MONTH)) OR (c.deleted=0 AND (c.status='Closed' OR c.expires_at<=UTC_TIMESTAMP()) AND GREATEST(COALESCE(c.modified_at,c.created_at),COALESCE((SELECT MAX(m.created_at) FROM nexa_chat_message m WHERE m.tenant_id=c.tenant_id AND m.service_id=c.service_id AND m.conversation_id=c.id),c.created_at))<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY)))";
    }
    private function finish(string $id): void
    {
        if(!$this->active()||$this->policy()['legalHold'])return;
        $pending=$this->store->rows('SELECT conversation_id FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND conversation_id=? AND completed_at IS NULL',[...$this->store->scope(),$id]);if(!$pending)return;
        try{
            // Remote object-store calls happen outside database admission locks.
            $query=$this->em->getQueryBuilder()->select()->from('Attachment')->withDeleted()->where(['OR'=>[['parentType'=>'NexaChatConversation','parentId'=>$id],['relatedType'=>'NexaChatConversation','relatedId'=>$id]]])->build();
            $files=$this->em->getRDBRepository('Attachment')->clone($query)->limit(0,20)->find();
            foreach($files as $file){
                $source=$file->getSourceId();$other=$this->store->rows("SELECT id FROM attachment WHERE tenant_id=? AND service_id=? AND id<>? AND COALESCE(NULLIF(source_id,''),id)=? LIMIT 1",[...$this->store->scope(),$file->getId(),$source]);
                if(!$other)$this->storage->unlink($file);
                $this->store->execute('DELETE FROM attachment WHERE tenant_id=? AND service_id=? AND id=?',[...$this->store->scope(),$file->getId()]);
            }
            $this->store->atomic(function()use($id):void{
                if($this->policy()['legalHold'])return;
                $receipt=$this->store->rows('SELECT completed_at FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND conversation_id=? FOR UPDATE',[...$this->store->scope(),$id])[0]??null;if(!$receipt||$receipt['completed_at'])return;
                if($this->store->rows("SELECT id FROM attachment WHERE tenant_id=? AND service_id=? AND ((parent_type='NexaChatConversation' AND parent_id=?) OR (related_type='NexaChatConversation' AND related_id=?)) LIMIT 1",[...$this->store->scope(),$id,$id])){$this->store->execute('UPDATE nexa_chat_erasure SET retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE tenant_id=? AND service_id=? AND conversation_id=?',[...$this->store->scope(),$id]);return;}
                $this->store->execute('DELETE FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND id=? AND deleted=1 AND privacy_erasing_at IS NOT NULL',[...$this->store->scope(),$id]);
                $this->store->execute('UPDATE nexa_chat_erasure SET completed_at=UTC_TIMESTAMP(),error_code=NULL WHERE tenant_id=? AND service_id=? AND conversation_id=? AND completed_at IS NULL',[...$this->store->scope(),$id]);
                $this->audit('chat.erasure_completed',$id,[]);
            },true);
        }catch(\Throwable $error){$this->store->execute("UPDATE nexa_chat_erasure SET attempts=attempts+1,error_code='storage_cleanup_failed',retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE tenant_id=? AND service_id=? AND conversation_id=? AND completed_at IS NULL",[...$this->store->scope(),$id]);}
    }
    public function run(): array
    {
        if(!$this->user->isSystem())throw new Forbidden('Chat retention is a background operation.');if(!$this->active()||$this->policy()['legalHold'])return ['processed'=>0];
        $rows=$this->store->rows('SELECT c.* FROM nexa_chat_conversation c WHERE c.tenant_id=? AND c.service_id=? AND '.$this->eligibility().' ORDER BY COALESCE(c.privacy_deleted_at,c.modified_at,c.created_at),c.id LIMIT 100',[...$this->store->scope(),$this->policy()['retentionDays']]);
        $processed=0;foreach($rows as $row)if($this->eligible($row)){try{$this->stage($row['id'],'retention');$processed++;}catch(Forbidden|NotFound $e){}}
        foreach($this->store->rows('SELECT conversation_id FROM nexa_chat_erasure WHERE tenant_id=? AND service_id=? AND completed_at IS NULL AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP()) ORDER BY COALESCE(retry_at,requested_at),conversation_id LIMIT 100',$this->store->scope()) as $row)$this->finish($row['conversation_id']);
        return ['processed'=>$processed];
    }
    private function audit(string $action,string $id,array $metadata): void
    {
        $actor=$this->user->isSystem()?null:$this->user->getId();$this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,?,?,?,'NexaChatConversation',?,?,'chat-privacy',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$actor?'user':'system',$actor,$action,$id,ChatStore::uuid(),json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
}
