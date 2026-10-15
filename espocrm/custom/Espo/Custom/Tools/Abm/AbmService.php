<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Abm;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\{ServiceFactory,CreateParams,UpdateParams,DeleteParams};
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Custom\Tools\Scoring\ScoringConditions;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;
use Espo\Entities\User;
use Espo\ORM\Entity;
use PDO;
use stdClass;

/** Account audiences reuse native Account/TargetList membership and Contact orchestration. */
final class AbmService
{
    private const FIELDS=['industry','type','billingAddressCountry','numberOfEmployees','leadScore','lifecycleStage','targetAccountTier','assignedUserId'];
    public function __construct(private EntityManager $em,private TenantContextStore $contexts,private User $user,private Acl $acl,private ServiceFactory $records,private InjectableFactory $factory,private AclManager $aclManager,private Metadata $metadata) {}

    public function workspace(): array
    {
        $this->access();$fields=$this->fields();$accounts=[];
        $rows=$this->all('SELECT id FROM account WHERE tenant_id=? AND service_id=? AND deleted=0 AND is_target_account=1 ORDER BY name,id LIMIT 5001',$this->scope());
        if(count($rows)>5000)throw new BadRequest('This account workspace supports at most 5,000 target accounts.');
        if($rows){
            $search=\Espo\Core\Select\SearchParams::fromRaw(['maxSize'=>5000,'orderBy'=>'name','order'=>'asc',
                'select'=>['id','name','isTargetAccount','targetAccountTier','industry','leadScore','assignedUserId','tenantId','serviceId'],
                'where'=>[['type'=>'in','attribute'=>'id','value'=>array_column($rows,'id')]]]);
            $collection=$this->records->createForUser('Account',$this->user)->find($search,\Espo\Core\Record\FindParams::create()->withNoTotal())->getCollection();
            $owners=[];
            foreach($collection as $record){
                if($record->get('tenantId')!==$this->scope()[0] || $record->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($record,'read'))continue;
                $accounts[]=$this->accountData($record,$owners);
            }
        }
        $lists=$this->all("SELECT b.target_list_id id,t.name,b.list_type type,b.version_number version,b.account_count accountCount,d.member_count contactCount,d.eligible_count eligibleCount,d.suppressed_count suppressedCount,b.conditions_json,b.last_refreshed_at lastRefreshedAt,b.automatic_enabled automaticEnabled,b.last_error_code lastErrorCode FROM nexa_abm_list b INNER JOIN target_list t ON t.id=b.target_list_id AND t.tenant_id=b.tenant_id AND t.service_id=b.service_id AND t.deleted=0 INNER JOIN nexa_segment_definition d ON d.target_list_id=b.target_list_id AND d.tenant_id=b.tenant_id AND d.service_id=b.service_id AND d.status='active' WHERE b.tenant_id=? AND b.service_id=? ORDER BY t.name,b.target_list_id LIMIT 201",$this->scope());
        if(count($lists)>200)throw new BadRequest('Archive unused account lists before exceeding 200 active lists.');
        foreach($lists as &$list){$list['conditions']=$list['conditions_json']?json_decode($list['conditions_json'],true,32,JSON_THROW_ON_ERROR):null;unset($list['conditions_json']);$list['accountIds']=array_column($this->all('SELECT account_id FROM account_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0 ORDER BY account_id',[...$this->scope(),$list['id']]),'account_id');}unset($list);
        $canManage=true;try{$this->entitled();}catch(Forbidden $error){$canManage=false;}
        return ['accounts'=>$accounts,'lists'=>$lists,'fields'=>$fields,'canManage'=>$canManage];
    }

    public function target(string $id,stdClass $input): array
    {
        $this->access();$this->entitled();if(array_diff(array_keys(get_object_vars($input)),['isTargetAccount','targetAccountTier','assignedUserId']) || !is_bool($input->isTargetAccount??null) || !in_array($input->targetAccountTier??null,['Tier1','Tier2','Tier3'],true))throw new BadRequest('Choose target status and a valid account tier.');
        return $this->transaction(function()use($id,$input):array{
            $this->lockTenant();$record=$this->account($id,true);$previous=['target'=>$record->get('isTargetAccount'),'tier'=>$record->get('targetAccountTier'),'ownerId'=>$record->get('assignedUserId')];$data=(object)['isTargetAccount'=>$input->isTargetAccount,'targetAccountTier'=>$input->targetAccountTier];
            foreach(['isTargetAccount','targetAccountTier'] as $field)if(!$this->acl->checkField('Account',$field,'edit'))throw new Forbidden('Target account property edit access is required.');
            if(property_exists($input,'assignedUserId')){
                if(!$this->acl->checkField('Account','assignedUser','edit'))throw new Forbidden('Account owner edit access is required.');
                if($input->assignedUserId!==null){$owner=is_string($input->assignedUserId)?$this->em->getEntityById('User',$input->assignedUserId):null;if(!$owner instanceof User || !$owner->isActive() || $owner->get('deleted') || !in_array($owner->getType(),['admin','regular'],true) || $owner->get('tenantId')!==$this->scope()[0] || $owner->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($owner,'read'))throw new BadRequest('Choose an active staff member in this workspace.');}
                $data->assignedUserId=$input->assignedUserId;
            }
            $this->records->createForUser('Account',$this->user)->update($id,$data,UpdateParams::create());
            $this->factory->createWith(\Espo\Custom\Tools\Customer\CustomerFoundationRecorder::class,['user'=>$this->user])->abmTargetRecorded($id,['previous'=>$previous,'current'=>get_object_vars($data)]);
            $this->execute('UPDATE nexa_abm_list SET next_refresh_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=?',$this->scope());
            return $this->accountData($this->account($id));
        });
    }

    public function save(stdClass $input): array
    {
        $this->access();$this->entitled();
        if(array_diff(array_keys(get_object_vars($input)),['id','name','type','conditions','accountIds','automaticEnabled','expectedVersion']) || !is_string($input->name??null) || trim($input->name)==='' || mb_strlen($input->name)>160 || !in_array($input->type??null,['static','dynamic'],true) || !is_bool($input->automaticEnabled??true))throw new BadRequest('Enter a list name, type and refresh setting.');
        $conditions=null;$ids=[];
        if($input->type==='dynamic'){
            if(!(($input->conditions??null) instanceof stdClass))throw new BadRequest('Enter account matching conditions.');
            $conditions=json_decode(json_encode($input->conditions,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);ScoringConditions::validate($conditions,$this->fields());$this->validateOwners($conditions);
            if(!isset($conditions['conditions']))throw new BadRequest('Choose an AND/OR account condition group.');
        }else{
            if(!is_array($input->accountIds??null) || !array_is_list($input->accountIds) || count($input->accountIds)>5000)throw new BadRequest('Choose up to 5,000 accounts.');
            foreach($input->accountIds as $id){if(!is_string($id) || !preg_match('/^[a-zA-Z0-9]{1,24}$/D',$id))throw new BadRequest('Choose valid accounts.');if(!$this->account($id)->get('isTargetAccount'))throw new BadRequest('Designate selected companies as target accounts before adding them to a list.');$ids[$id]=true;}$ids=array_keys($ids);
        }
        return $this->transaction(function()use($input,$conditions,$ids):array{
            $this->lockTenant();$id=$input->id??null;
            if($id!==null){if(!is_string($id))throw new BadRequest('Choose a valid list.');$prior=$this->list($id,true);if(!is_int($input->expectedVersion??null) || $input->expectedVersion!==(int)$prior['version_number'])throw new BadRequest('This account list changed. Reload it before saving.');$this->records->createForUser('TargetList',$this->user)->update($id,(object)['name'=>trim($input->name)],UpdateParams::create());}
            else{
                if(count($this->all('SELECT target_list_id FROM nexa_abm_list WHERE tenant_id=? AND service_id=?',$this->scope()))>=200)throw new BadRequest('This workspace supports up to 200 stored account lists.');
                $native=$this->records->createForUser('TargetList',$this->user)->create((object)['name'=>trim($input->name)],CreateParams::create());$id=$native->getId();
                $segments=$this->segments();$segments->getWorkspace();
                $segments->save((object)['name'=>trim($input->name),'type'=>'dynamic','matchMode'=>'all','rules'=>[(object)['field'=>'accountListId','operator'=>'equals','value'=>$id]]],$id);
            }
            $this->execute('INSERT INTO nexa_abm_list (target_list_id,tenant_id,service_id,list_type,conditions_json,execution_user_id,automatic_enabled) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE list_type=VALUES(list_type),conditions_json=VALUES(conditions_json),execution_user_id=VALUES(execution_user_id),automatic_enabled=VALUES(automatic_enabled),version_number=version_number+1,next_refresh_at=UTC_TIMESTAMP(6),last_error_code=NULL',[$id,...$this->scope(),$input->type,$conditions?json_encode($conditions,JSON_THROW_ON_ERROR):null,$this->user->getId(),($input->automaticEnabled??true)?1:0]);
            if($input->type==='static')$this->replaceAccounts($id,$ids);
            $result=$this->refresh($id);$this->audit('abm.list.saved',$id,['type'=>$input->type,'conditions'=>$conditions,'accountIds'=>$ids]);return $result;
        });
    }

    public function refresh(string $id): array
    {
        $this->access();$this->entitled();return $this->transaction(function()use($id):array{
            $this->lockTenant();$list=$this->list($id,true);
            if($list['list_type']==='dynamic'){
                $conditions=json_decode($list['conditions_json'],true,32,JSON_THROW_ON_ERROR);$fields=$this->fields();ScoringConditions::validate($conditions,$fields);$this->validateOwners($conditions);
                $rows=$this->all('SELECT id FROM account WHERE tenant_id=? AND service_id=? AND deleted=0 AND is_target_account=1 ORDER BY id LIMIT 5001',$this->scope());if(count($rows)>5000)throw new BadRequest('Narrow the workspace to at most 5,000 target accounts.');
                $matched=[];foreach($rows as $row){$record=$this->account($row['id']);$inputs=[];foreach(ScoringConditions::leaves($conditions) as $leaf)$inputs[$leaf['field']]=$record->get($leaf['field']);if(ScoringConditions::evaluate($conditions,$fields,$inputs)['matched'])$matched[]=$record->getId();}$this->replaceAccounts($id,$matched);
            }
            $accounts=$this->all('SELECT a.id FROM account_target_list atl INNER JOIN account a ON a.id=atl.account_id AND a.tenant_id=atl.tenant_id AND a.service_id=atl.service_id AND a.deleted=0 AND a.is_target_account=1 WHERE atl.tenant_id=? AND atl.service_id=? AND atl.target_list_id=? AND atl.deleted=0 AND atl.opted_out=0',[...$this->scope(),$id]);
            foreach($accounts as $row)$this->account($row['id']);
            $segments=$this->segments();$preview=$segments->preview((object)['matchMode'=>'all','rules'=>[(object)['field'=>'accountListId','operator'=>'equals','value'=>$id]]]);
            if($preview['memberCount']>5000)throw new BadRequest('This account audience supports at most 5,000 associated contacts.');
            $result=$segments->recalculate($id, false, true);
            $this->execute('UPDATE nexa_abm_list SET account_count=?,last_refreshed_at=UTC_TIMESTAMP(6),next_refresh_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR),last_error_code=NULL WHERE tenant_id=? AND service_id=? AND target_list_id=?',[count($accounts),...$this->scope(),$id]);
            $this->audit('abm.list.refreshed',$id,['accountCount'=>count($accounts),'contactCount'=>$result['memberCount'],'runId'=>$result['runId']]);
            return $result+['accountCount'=>count($accounts)];
        });
    }

    public function archive(string $id): array
    {
        $this->access();return $this->transaction(function()use($id):array{$this->lockTenant();$this->list($id,true);$this->segments()->archive($id);$this->execute('UPDATE nexa_abm_list SET automatic_enabled=0 WHERE tenant_id=? AND service_id=? AND target_list_id=?',[...$this->scope(),$id]);$this->audit('abm.list.archived',$id,[]);return ['id'=>$id];});
    }

    public function accountSummary(string $id): array
    {
        $this->access();$record=$this->account($id);$this->requireAudienceRead();
        $rows=$this->all('SELECT DISTINCT c.id FROM contact c WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND (c.account_id=? OR EXISTS(SELECT 1 FROM account_contact ac WHERE ac.tenant_id=c.tenant_id AND ac.service_id=c.service_id AND ac.contact_id=c.id AND ac.account_id=? AND ac.deleted=0)) ORDER BY c.id LIMIT 5001',[...$this->scope(),$id,$id]);
        if(count($rows)>5000)throw new BadRequest('Account engagement supports up to 5,000 associated contacts.');
        $contacts=[];$ids=[];
        if($rows){
            $search=\Espo\Core\Select\SearchParams::fromRaw(['maxSize'=>5000,'orderBy'=>'id','order'=>'asc',
                'select'=>['id','name','firstName','lastName','title','tenantId','serviceId'],
                'where'=>[['type'=>'in','attribute'=>'id','value'=>array_column($rows,'id')]]]);
            $collection=$this->records->createForUser('Contact',$this->user)->find($search,\Espo\Core\Record\FindParams::create()->withNoTotal())->getCollection();
            foreach($collection as $contact){
                if($contact->get('tenantId')!==$this->scope()[0] || $contact->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($contact,'read'))continue;
                $ids[]=$contact->getId();
                if(count($contacts)<50)$contacts[]=['id'=>$contact->getId(),'name'=>$this->acl->checkField('Contact','name','read')&&$this->acl->checkField('Contact','firstName','read')&&$this->acl->checkField('Contact','lastName','read')?($contact->get('name')?:trim(($contact->get('firstName')??'').' '.($contact->get('lastName')??''))):'Contact','title'=>$this->acl->checkField('Contact','title','read')?$contact->get('title'):null];
            }
        }
        $engagement=['pageViews'=>0,'emailOpens'=>0,'emailClicks'=>0,'emailReplies'=>0,'lastEngagedAt'=>null];
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$events=$this->all("SELECT event_type,source_entity_type,source_entity_id,MAX(source_occurred_at) lastAt FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id IN ($marks) AND visibility IN ('internal','team','customer') AND source_entity_id IS NOT NULL AND source_occurred_at BETWEEN DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 30 DAY) AND UTC_TIMESTAMP(6) AND event_type IN ('page.viewed','email.opened','email.clicked','email.replied') GROUP BY event_type,source_entity_type,source_entity_id LIMIT 10001",[...$this->scope(),...$ids]);if(count($events)>10000)throw new BadRequest('Narrow account engagement to at most 10,000 distinct events.');
            $keys=['page.viewed'=>'pageViews','email.opened'=>'emailOpens','email.clicked'=>'emailClicks','email.replied'=>'emailReplies'];$sources=\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::EVENTS;
            foreach($events as $event){if(($sources[$event['event_type']]??null)!==$event['source_entity_type'])continue;$engagement[$keys[$event['event_type']]]++;if(!$engagement['lastEngagedAt'] || $event['lastAt']>$engagement['lastEngagedAt'])$engagement['lastEngagedAt']=$event['lastAt'];}
        }
        return ['account'=>$this->accountData($record),'contactCount'=>count($ids),'contacts'=>$contacts,'hasMoreContacts'=>count($ids)>50,'engagement'=>$engagement];
    }

    public function processDue(): int
    {
        if(!$this->user->isSystem())throw new Forbidden('Account list scheduling is a background-job operation.');$processed=0;
        foreach($this->all('SELECT target_list_id FROM nexa_abm_list WHERE tenant_id=? AND service_id=? AND automatic_enabled=1 AND next_refresh_at<=UTC_TIMESTAMP(6) ORDER BY next_refresh_at,target_list_id LIMIT 5',$this->scope()) as $candidate){
            $processed+=$this->transaction(function()use($candidate):int{
                $this->lockTenant();$row=$this->one('SELECT * FROM nexa_abm_list WHERE tenant_id=? AND service_id=? AND target_list_id=? FOR UPDATE',[...$this->scope(),$candidate['target_list_id']]);$now=$this->one('SELECT UTC_TIMESTAMP(6) now',[])['now'];if(!$row || !$row['automatic_enabled'] || $row['next_refresh_at']>$now)return 0;
                $actor=$this->em->getEntityById('User',$row['execution_user_id']);
                try{if(!$actor instanceof User || !$actor->isActive() || $actor->get('deleted') || !$actor->isAdmin() || $actor->get('tenantId')!==$this->scope()[0] || $actor->get('serviceId')!==$this->scope()[1])throw new Forbidden('Execution identity unavailable.');$service=$this->factory->createWith(self::class,['user'=>$actor,'acl'=>$this->aclManager->createUserAcl($actor)]);$service->refresh($row['target_list_id']);return 1;}
                catch(BadRequest|Forbidden|NotFound $error){$this->execute("UPDATE nexa_abm_list SET automatic_enabled=0,last_error_code='configuration_or_access_invalid' WHERE tenant_id=? AND service_id=? AND target_list_id=?",[...$this->scope(),$row['target_list_id']]);return 0;}
            });
        }return $processed;
    }

    private function fields(): array
    {
        $fields=[];foreach(self::FIELDS as $field){$aclField=$field==='assignedUserId'?'assignedUser':$field;if(!$this->acl->checkField('Account',$aclField,'read'))continue;$meta=$this->metadata->get(['entityDefs','Account','fields',$aclField],[]);$fields[$field]=['type'=>$field==='assignedUserId'?'varchar':($meta['type']??'varchar'),'options'=>$meta['options']??[],'label'=>$field==='assignedUserId'?'Account owner':($field==='billingAddressCountry'?'Country':ucfirst(preg_replace('/([a-z])([A-Z])/','$1 $2',$field)))];}return $fields;
    }
    private function validateOwners(array $conditions):void
    {
        foreach(ScoringConditions::leaves($conditions) as $leaf)if($leaf['field']==='assignedUserId' && $leaf['operator']!=='has_value'){$owner=$this->em->getEntityById('User',$leaf['value']);if(!$owner instanceof User || $owner->get('deleted') || $owner->get('tenantId')!==$this->scope()[0] || $owner->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($owner,'read'))throw new BadRequest('Choose an account owner in this workspace.');}
    }
    private function replaceAccounts(string $id,array $ids): void
    {
        if(!$this->acl->checkField('TargetList','accounts','edit'))throw new Forbidden('Account list membership edit access is required.');
        $current=array_column($this->all('SELECT account_id FROM account_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0',[...$this->scope(),$id]),'account_id');
        $native=$this->records->createForUser('TargetList',$this->user);
        foreach(array_diff($ids,$current) as $accountId){$this->account($accountId);$native->link($id,'accounts',$accountId);}
        foreach(array_diff($current,$ids) as $accountId){
            try{$this->account($accountId);$native->unlink($id,'accounts',$accountId);}
            catch(NotFound $error){$this->execute('UPDATE account_target_list SET deleted=1 WHERE tenant_id=? AND service_id=? AND target_list_id=? AND account_id=?',[...$this->scope(),$id,$accountId]);}
        }
    }
    private function accountData(Entity $record, ?array &$owners=null): array
    {
        $data=['id'=>$record->getId()];foreach(['name','isTargetAccount','targetAccountTier','industry','leadScore','assignedUserId'] as $field)if($this->acl->checkField('Account',$field==='assignedUserId'?'assignedUser':$field,'read'))$data[$field]=$record->get($field);
        $data['assignedUserName']=null;
        if(isset($data['assignedUserId'])){
            $ownerId=$data['assignedUserId'];
            if($owners!==null){if(!array_key_exists($ownerId,$owners))$owners[$ownerId]=$this->em->getEntityById('User',$ownerId);$owner=$owners[$ownerId];}
            else $owner=$this->em->getEntityById('User',$ownerId);
            if($owner && $owner->get('tenantId')===$this->scope()[0] && $owner->get('serviceId')===$this->scope()[1] && $this->acl->checkEntity($owner,'read'))$data['assignedUserName']=$owner->get('name')?:$owner->get('userName');
        }
        return $data;
    }
    private function account(string $id,bool $edit=false): Entity
    {
        $record=$this->records->createForUser('Account',$this->user)->getEntity($id);if(!$record || $record->get('deleted') || $record->get('tenantId')!==$this->scope()[0] || $record->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($record,$edit?'edit':'read'))throw new NotFound('Account unavailable.');return $record;
    }
    private function list(string $id,bool $lock=false): array
    {
        $native=$this->records->createForUser('TargetList',$this->user)->getEntity($id);if(!$native || $native->get('deleted') || $native->get('tenantId')!==$this->scope()[0] || $native->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($native,'edit'))throw new NotFound('Account list unavailable.');return $this->one('SELECT * FROM nexa_abm_list WHERE tenant_id=? AND service_id=? AND target_list_id=?'.($lock?' FOR UPDATE':''),[...$this->scope(),$id])??throw new NotFound('Account list unavailable.');
    }
    private function access(): void
    {
        $this->contexts->require();if(!$this->user->isActive() || !$this->user->isAdmin() || $this->user->getType()!=='admin' || $this->user->get('tenantId')!==$this->scope()[0] || $this->user->get('serviceId')!==$this->scope()[1] || !$this->acl->checkScope('Account','read') || !$this->acl->checkField('Account','isTargetAccount','read') || !$this->acl->checkField('Account','targetAccountTier','read'))throw new Forbidden('Tenant administrator target-account access is required.');$this->requireAudienceRead();
    }
    private function requireAudienceRead(): void{if(!$this->acl->checkScope('Contact','read') || !$this->acl->checkField('Account','contacts','read') || !$this->acl->checkField('Contact','account','read'))throw new Forbidden('Contact relationship read access is required.');}
    private function entitled():void{$this->factory->create(\Espo\Custom\Tools\Automation\WorkflowQuota::class)->limits();}
    private function segments(): SegmentWorkspaceService{return $this->factory->createWith(SegmentWorkspaceService::class,['user'=>$this->user,'acl'=>$this->acl]);}
    private function audit(string $action,string $id,array $data):void{$this->factory->createWith(\Espo\Custom\Tools\Customer\CustomerFoundationRecorder::class,['user'=>$this->user])->abmRecorded($action,$id,$data);}
    private function scope():array{$context=$this->contexts->require();return[$context->tenantId,$context->serviceId];}
    private function lockTenant():void{$this->one('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE',[$this->scope()[0]]);}
    private function all(string $sql,array $args):array{$q=$this->em->getPDO()->prepare($sql);$q->execute($args);return$q->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $args):?array{return$this->all($sql,$args)[0]??null;}
    private function execute(string $sql,array $args):void{$q=$this->em->getPDO()->prepare($sql);$q->execute($args);}
    private function transaction(callable $fn):mixed{$pdo=$this->em->getPDO();$tx=$this->em->getTransactionManager();$owns=!$pdo->inTransaction();$save='abm_'.bin2hex(random_bytes(5));if($owns)$tx->start();else$pdo->exec('SAVEPOINT '.$save);try{$result=$fn();if($owns)$tx->commit();else$pdo->exec('RELEASE SAVEPOINT '.$save);return$result;}catch(\Throwable $error){if($owns&&$tx->isStarted())$tx->rollback();elseif($pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}throw$error;}}
}
