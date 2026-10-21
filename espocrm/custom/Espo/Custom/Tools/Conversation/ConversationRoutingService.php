<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\AclManager;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Entities\User;
use stdClass;

/** Presence is explicit and leased; native record ownership remains authoritative. */
final class ConversationRoutingService
{
    public const CHANNELS=['chat','sms','whatsapp','messenger'];
    public function __construct(private ChatStore $store,private EntityManager $em,private User $user,private AclManager $acls,private ServiceFactory $records) {}
    private function active(): bool
    {
        return (bool)$this->store->rows("SELECT ts.tenant_id FROM nexa_tenant_service ts JOIN nexa_tenant t ON t.id=ts.tenant_id AND t.status='active' JOIN nexa_service_definition d ON d.id=ts.service_id AND d.status='active' WHERE ts.tenant_id=? AND ts.service_id=? AND ts.status='active'",$this->store->scope());
    }
    private function staff(): void
    {
        if(!$this->active()||!in_array($this->user->getType(),['admin','regular'],true)||[$this->user->get('tenantId'),$this->user->get('serviceId')]!==$this->store->scope()||!$this->store->rows("SELECT id FROM user WHERE tenant_id=? AND service_id=? AND id=? AND is_active=1 AND deleted=0 AND type=?",[...$this->store->scope(),$this->user->getId(),$this->user->getType()]))throw new Forbidden('Active workspace staff access is required.');
        $acl=$this->acls->createUserAcl($this->user);
        if(!$acl->checkScope('NexaChatConversation','read')&&!$acl->checkScope('NexaMessagingConversation','read'))throw new Forbidden('Conversation access is required.');
    }
    public function process(stdClass $in): array
    {
        $this->staff();
        if(strlen(json_encode($in,JSON_THROW_ON_ERROR))>4000)throw new BadRequest('Routing request is too large.');
        return match($in->op??''){'presence'=>$this->presence($in),'workspace'=>$this->workspace(),'save'=>$this->save($in),default=>throw new BadRequest('Choose a routing operation.')};
    }
    private function presence(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','available','version','heartbeat'])||(property_exists($in,'available')&&!is_bool($in->available))||(property_exists($in,'version')&&!is_int($in->version))||(property_exists($in,'heartbeat')&&!is_bool($in->heartbeat)))throw new BadRequest('Choose a valid availability state.');
        return $this->store->atomic(function()use($in):array{
            $this->staff();$args=[...$this->store->scope(),$this->user->getId()];
            $row=$this->store->rows('SELECT *,expires_at>UTC_TIMESTAMP() AS fresh FROM nexa_conversation_presence WHERE tenant_id=? AND service_id=? AND user_id=? FOR UPDATE',$args)[0]??null;
            if(property_exists($in,'available')){
                if(($row?(int)$row['version_number']:0)!==($in->version??null))throw new Conflict('Availability changed in another tab.');
                $this->store->execute('INSERT INTO nexa_conversation_presence (tenant_id,service_id,user_id,is_available,expires_at) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND)) ON DUPLICATE KEY UPDATE is_available=VALUES(is_available),expires_at=VALUES(expires_at),version_number=version_number+1',[...$args,(int)$in->available]);
            }elseif(($in->heartbeat??false)&&$row&&$row['is_available']&&$row['fresh']&&(int)$row['version_number']===($in->version??null)){
                $this->store->execute('UPDATE nexa_conversation_presence SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND) WHERE tenant_id=? AND service_id=? AND user_id=?',$args);
            }
            $row=$this->store->rows('SELECT is_available AND expires_at>UTC_TIMESTAMP() AS available,version_number,expires_at FROM nexa_conversation_presence WHERE tenant_id=? AND service_id=? AND user_id=?',$args)[0]??null;
            return ['available'=>(bool)($row['available']??false),'version'=>(int)($row['version_number']??0),'expiresAt'=>$row['expires_at']??null,'canConfigure'=>$this->user->isAdmin()];
        },true);
    }
    private function admin(): void { $this->staff();if(!$this->user->isAdmin())throw new Forbidden('Tenant administrator access is required.'); }
    private function workspace(): array
    {
        $this->admin();$acl=$this->acls->createUserAcl($this->user);$teams=[];
        foreach($this->store->rows('SELECT id FROM team WHERE tenant_id=? AND service_id=? AND deleted=0 ORDER BY name,id LIMIT 200',$this->store->scope()) as $row){
            try{$team=$this->records->createForUser('Team',$this->user)->getEntity($row['id']);if($team&&$acl->checkEntity($team,'read')&&$acl->checkField('Team','name','read'))$teams[]=['id'=>$team->getId(),'name'=>$team->get('name')];}catch(Forbidden|NotFound $e){}
        }
        $rules=$this->store->rows('SELECT channel,team_id AS teamId,is_enabled AS enabled,capacity,version_number AS version FROM nexa_conversation_routing_rule WHERE tenant_id=? AND service_id=? ORDER BY channel',$this->store->scope());
        foreach($rules as &$rule){$rule['enabled']=(bool)$rule['enabled'];$rule['capacity']=(int)$rule['capacity'];$rule['version']=(int)$rule['version'];}unset($rule);
        $waiting=$this->store->rows("SELECT 'chat' AS channel,COUNT(*) AS total FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND deleted=0 AND status<>'Closed' AND expires_at>UTC_TIMESTAMP() AND assigned_user_id IS NULL AND handoff_requested_at IS NOT NULL UNION ALL SELECT p.channel,COUNT(*) FROM nexa_messaging_conversation c JOIN nexa_messaging_connection p ON p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND c.status<>'Closed' AND c.assigned_user_id IS NULL GROUP BY p.channel",[...$this->store->scope(),...$this->store->scope()]);
        return ['rules'=>$rules,'teams'=>$teams,'waiting'=>$waiting];
    }
    private function save(stdClass $in): array
    {
        $this->admin();
        if(array_diff(array_keys((array)$in),['op','channel','teamId','enabled','capacity','version'])||!in_array($in->channel??null,self::CHANNELS,true)||!is_bool($in->enabled??null)||!is_int($in->capacity??null)||$in->capacity<1||$in->capacity>50||!is_int($in->version??null)||!property_exists($in,'teamId')||!($in->teamId===null||is_string($in->teamId)&&preg_match('/^[A-Za-z0-9]{1,17}$/D',$in->teamId)))throw new BadRequest('Choose a channel, team and capacity between 1 and 50.');
        return $this->store->atomic(function()use($in):array{
            $this->admin();if($in->teamId!==null){$team=$this->records->createForUser('Team',$this->user)->getEntity($in->teamId);if(!$team||[$team->get('tenantId'),$team->get('serviceId')]!==$this->store->scope())throw new NotFound('Team unavailable.');}
            $old=$this->store->rows('SELECT version_number FROM nexa_conversation_routing_rule WHERE tenant_id=? AND service_id=? AND channel=? FOR UPDATE',[...$this->store->scope(),$in->channel])[0]??null;
            if(($old?(int)$old['version_number']:0)!==$in->version)throw new Conflict('Routing settings changed. Refresh before saving.');
            $this->store->execute('INSERT INTO nexa_conversation_routing_rule (tenant_id,service_id,channel,team_id,is_enabled,capacity) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE team_id=VALUES(team_id),is_enabled=VALUES(is_enabled),capacity=VALUES(capacity),version_number=version_number+1,modified_at=UTC_TIMESTAMP()',[...$this->store->scope(),$in->channel,$in->teamId,(int)$in->enabled,$in->capacity]);
            $this->audit('routing.settings_updated',$in->channel,['teamId'=>$in->teamId,'enabled'=>$in->enabled,'capacity'=>$in->capacity],$this->user->getId());
            return $this->workspace();
        },true);
    }
    private function audit(string $action,string $id,array $data,?string $actor=null): void
    {
        $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,?,?,?,'ConversationRouting',?,?,'conversation-routing',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$actor?'user':'system',$actor,$action,$id,ChatStore::uuid(),json_encode($data,JSON_THROW_ON_ERROR)]);
    }
    public function run(): int
    {
        if(!$this->user->isSystem())throw new Forbidden('Routing is a background-job operation.');
        if(!$this->active())return 0;$count=0;$pending=[];
        foreach(self::CHANNELS as $channel){
            $rule=$this->store->rows('SELECT * FROM nexa_conversation_routing_rule WHERE tenant_id=? AND service_id=? AND channel=? AND is_enabled=1',[...$this->store->scope(),$channel])[0]??null;if(!$rule)continue;
            $table=$channel==='chat'?'nexa_chat_conversation':'nexa_messaging_conversation';
            $extra=$channel==='chat'?' AND c.handoff_requested_at IS NOT NULL AND c.handoff_answered_at IS NULL AND c.expires_at>UTC_TIMESTAMP()':" AND EXISTS (SELECT 1 FROM nexa_messaging_connection p WHERE p.id=c.connection_id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.channel=? AND p.status='verified')";
            $args=[...$this->store->scope()];if($channel!=='chat')$args[]=$channel;
            $rows=$this->store->rows("SELECT c.id,c.routing_checked_at,c.created_at FROM $table c WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND c.status<>'Closed' AND c.assigned_user_id IS NULL$extra ORDER BY COALESCE(c.routing_checked_at,'1000-01-01'),c.created_at,c.id LIMIT 20",$args);
            foreach($rows as $row)$pending[]=$row+['channel'=>$channel];
        }
        // One shared oldest-attempt queue prevents a permanently preferred channel.
        usort($pending,fn($a,$b)=>strcmp($a['routing_checked_at']??'1000-01-01',$b['routing_checked_at']??'1000-01-01')?:strcmp($a['created_at'],$b['created_at'])?:strcmp($a['id'],$b['id']));
        foreach(array_slice($pending,0,20) as $row)$count+=(int)$this->route($row['channel'],$row['id']);
        return $count;
    }
    private function route(string $channel,string $id): bool
    {
        return $this->store->atomic(function()use($channel,$id):bool{
            if(!$this->active())return false;
            $rule=$this->store->rows('SELECT * FROM nexa_conversation_routing_rule WHERE tenant_id=? AND service_id=? AND channel=? AND is_enabled=1 FOR UPDATE',[...$this->store->scope(),$channel])[0]??null;if(!$rule)return false;
            $type=$channel==='chat'?'NexaChatConversation':'NexaMessagingConversation';$table=$channel==='chat'?'nexa_chat_conversation':'nexa_messaging_conversation';
            $row=$this->store->rows("SELECT * FROM $table WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE",[...$this->store->scope(),$id])[0]??null;
            if(!$row||$row['assigned_user_id']!==null||$row['status']==='Closed'||($channel==='chat'&&(!$row['handoff_requested_at']||$row['handoff_answered_at']||$row['expires_at']<=gmdate('Y-m-d H:i:s'))))return false;
            if($channel!=='chat'&&!$this->store->rows("SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND channel=? AND status='verified'",[...$this->store->scope(),$row['connection_id'],$channel]))return false;
            $root=$this->em->getEntityById($type,$id);if(!$root)return false;
            $pool='';$args=[...$this->store->scope()];
            if($rule['team_id']){$pool=' AND EXISTS (SELECT 1 FROM team_user tu JOIN team t ON t.id=tu.team_id AND t.tenant_id=tu.tenant_id AND t.service_id=tu.service_id AND t.deleted=0 WHERE tu.user_id=u.id AND tu.tenant_id=u.tenant_id AND tu.service_id=u.service_id AND tu.team_id=? AND tu.deleted=0)';$args[]=$rule['team_id'];}
            $candidates=$this->store->rows("SELECT u.id,p.last_assigned_at FROM nexa_conversation_presence p JOIN user u ON u.id=p.user_id AND u.tenant_id=p.tenant_id AND u.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND p.is_available=1 AND p.expires_at>UTC_TIMESTAMP() AND u.deleted=0 AND u.is_active=1 AND u.type IN ('admin','regular')$pool ORDER BY COALESCE(p.last_assigned_at,'1000-01-01'),u.id LIMIT 200 FOR UPDATE",$args);
            $chosen=null;$load=null;
            foreach($candidates as $candidate){
                $agent=$this->em->getEntityById('User',$candidate['id']);if(!$agent instanceof User)continue;$acl=$this->acls->createUserAcl($agent);$probe=clone $root;$probe->set('assignedUserId',$agent->getId());
                if(!$acl->checkScope($type,'read')||!$acl->checkEntity($probe,'read')||!$acl->checkEntity($probe,'edit')||!$acl->checkField($type,'messages','read')||!$acl->checkField($type,'messages','edit'))continue;
                $visible=true;foreach(['name','status','assignedUser'] as $field)if(!$acl->checkField($type,$field,'read'))$visible=false;if(!$visible)continue;
                $work=$this->store->rows("SELECT (SELECT COUNT(*) FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND assigned_user_id=? AND deleted=0 AND status<>'Closed' AND expires_at>UTC_TIMESTAMP())+(SELECT COUNT(*) FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND assigned_user_id=? AND deleted=0 AND status<>'Closed') AS total",[...$this->store->scope(),$agent->getId(),...$this->store->scope(),$agent->getId()])[0]['total'];
                if((int)$work>=(int)$rule['capacity'])continue;if($chosen===null||(int)$work<$load){$chosen=$agent;$load=(int)$work;}
            }
            if(!$chosen){$changed=$row['routing_state']!=='waiting'||$row['routing_reason']!=='no_available_agent';$this->store->execute("UPDATE $table SET routing_state='waiting',routing_reason='no_available_agent',routing_checked_at=UTC_TIMESTAMP(),routing_team_id=? WHERE tenant_id=? AND service_id=? AND id=?",[$rule['team_id'],...$this->store->scope(),$id]);if($changed)$this->audit('routing.waiting',$id,['channel'=>$channel,'teamId'=>$rule['team_id']]);return false;}
            $root->set(['assignedUserId'=>$chosen->getId(),'routingState'=>'assigned','routingReason'=>null,'routingCheckedAt'=>gmdate('Y-m-d H:i:s'),'routingAssignedAt'=>gmdate('Y-m-d H:i:s'),'routingTeamId'=>$rule['team_id']]);$this->em->saveEntity($root,['silent'=>true,'skipModifiedBy'=>true]);
            $this->store->execute('UPDATE nexa_conversation_presence SET last_assigned_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND user_id=?',[...$this->store->scope(),$chosen->getId()]);
            $this->em->createEntity('Notification',['type'=>'Assign','userId'=>$chosen->getId(),'data'=>(object)['entityType'=>$type,'entityId'=>$id,'entityName'=>$root->get('name')]],['silent'=>true,'skipCreatedBy'=>true]);
            $this->audit('routing.assigned',$id,['channel'=>$channel,'teamId'=>$rule['team_id'],'assignedUserId'=>$chosen->getId(),'previousLoad'=>$load]);return true;
        },true);
    }
}
