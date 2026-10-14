<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use PDO;
use stdClass;

final class ScoringService
{
    private const FIELDS=[
        'Contact'=>['contactSource','lifecycleStage','leadStatus','marketingStatus','customerStage','title','department','addressCountry'],
        'Account'=>['industry','type','numberOfEmployees','lifecycleStage','leadStatus','billingAddressCountry'],
        'Opportunity'=>['stage','probability','leadSource'],
    ];
    public function __construct(private EntityManager $em,private TenantContextStore $contexts,private Metadata $metadata,private User $user,private Acl $acl) {}

    public function workspace(): array
    {
        $this->access();$fields=[];
        foreach (array_keys(self::FIELDS) as $type) if ($this->acl->checkScope($type,'read')) $fields[$type]=$this->fields($type);
        $models=$this->all('SELECT id,name,entity_type entityType,status,version_number version,modified_at modifiedAt,definition_json FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? ORDER BY modified_at DESC,id LIMIT 201',$this->scope());
        if (count($models)>200) throw new BadRequest('Archive unused drafts before adding more models.');
        $models=array_values(array_filter($models,function($row)use($fields):bool {
            if (!isset($fields[$row['entityType']])) return false;
            foreach (json_decode($row['definition_json'],true,32,JSON_THROW_ON_ERROR)['rules'] as $rule) if (!isset($fields[$row['entityType']][$rule['field']])) return false;
            return true;
        }));
        foreach ($models as &$row) {$row['definition']=json_decode($row['definition_json'],true,32,JSON_THROW_ON_ERROR);unset($row['definition_json']);}
        return ['models'=>$models,'fields'=>$fields,'publishedLimit'=>$this->limit(),'canManage'=>$this->user->isAdmin()];
    }

    public function save(stdClass $input): array
    {
        $this->access(true);
        if (array_diff(array_keys(get_object_vars($input)),['id','name','entityType','definition']) || !is_string($input->name??null) || trim($input->name)==='' || mb_strlen($input->name)>160 || !is_string($input->entityType??null) || !isset(self::FIELDS[$input->entityType])) throw new BadRequest('Enter a model name and supported record type.');
        $type=$input->entityType;$this->requireType($type);
        if (!(($input->definition??null) instanceof stdClass)) throw new BadRequest('Enter a scoring rule definition.');
        $definition=ScoringDefinition::validate(json_decode(json_encode($input->definition??null,JSON_THROW_ON_ERROR),true)??[],$this->fields($type));
        return $this->transaction(function()use($input,$type,$definition):array {
            $this->lockTenant();$id=$input->id??null;
            if ($id!==null) {
                if (!is_string($id)) throw new BadRequest('Invalid model identifier.');
                $model=$this->model($id,true);
                if ($model['status']!=='draft' || $model['entity_type']!==$type) throw new BadRequest('Published models are immutable. Create a separate draft to change their rules.');
            } else {
                if (count($this->all('SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=?',$this->scope()))>=200) throw new BadRequest('This workspace supports at most 200 stored models.');
                $id=$this->uuid();
            }
            $json=json_encode($definition,JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
            if (isset($model)) $this->execute('UPDATE nexa_scoring_model SET name=?,definition_json=?,definition_hash=?,modified_by_id=?,version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?',[trim($input->name),$json,$hash,$this->user->getId(),...$this->scope(),$id]);
            else $this->execute('INSERT INTO nexa_scoring_model (id,tenant_id,service_id,name,entity_type,definition_json,definition_hash,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?)',[$id,...$this->scope(),trim($input->name),$type,$json,$hash,$this->user->getId(),$this->user->getId()]);
            return ['id'=>$id,'status'=>'draft'];
        });
    }

    public function control(string $id,stdClass $input): array
    {
        $this->access(true);
        if (array_diff(array_keys(get_object_vars($input)),['action']) || !in_array($input->action??null,['publish','archive'],true)) throw new BadRequest('Choose publish or archive.');
        return $this->transaction(function()use($id,$input):array {
            $this->lockTenant();$model=$this->model($id,true);$this->requireType($model['entity_type']);
            if ($input->action==='publish') {
                if ($model['status']!=='draft') throw new BadRequest('Only drafts can be published.');
                ScoringDefinition::validate($this->definition($model),$this->fields($model['entity_type']));
                $count=$this->all("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND status='published'",$this->scope());
                if (count($count)>=$this->limit()) throw new BadRequest('The plan limit for active scoring models has been reached.');
                $this->execute('INSERT INTO nexa_scoring_version (tenant_id,service_id,model_id,version_number,definition_json,definition_hash,published_by_id) VALUES (?,?,?,?,?,?,?)',[...$this->scope(),$id,$model['version_number'],$model['definition_json'],$model['definition_hash'],$this->user->getId()]);
            } elseif ($model['status']==='archived') throw new BadRequest('This model is already archived.');
            $status=$input->action==='publish'?'published':'archived';
            $this->execute('UPDATE nexa_scoring_model SET status=?,modified_by_id=? WHERE tenant_id=? AND service_id=? AND id=?',[$status,$this->user->getId(),...$this->scope(),$id]);
            return ['id'=>$id,'status'=>$status];
        });
    }

    public function preview(string $id,stdClass $input,bool $persist=false): array
    {
        $this->access();
        if (array_diff(array_keys(get_object_vars($input)),['recordId','requestKey']) || !is_string($input->recordId??null) || !preg_match('/^[a-zA-Z0-9]{1,24}$/D',$input->recordId)) throw new BadRequest('Choose a CRM record.');
        if ($persist && (!$this->user->isAdmin() || !is_string($input->requestKey??null) || !preg_match('/^[a-zA-Z0-9._:-]{8,128}$/D',$input->requestKey))) throw new Forbidden('A tenant administrator and a stable evaluation request key are required.');
        return $this->transaction(function()use($id,$input,$persist):array {
            if ($persist) $this->lockTenant();
            $model=$this->model($id,$persist);$type=$model['entity_type'];$this->requireType($type);
            $record=$this->em->getEntityById($type,$input->recordId);
            if (!$record || $record->get('deleted') || $record->get('tenantId')!==$this->scope()[0] || $record->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($record,'read')) throw new NotFound('Record not found.');
            $definition=$this->definition($model);$fields=$this->fields($type);
            foreach ($definition['rules'] as $rule) if (!isset($fields[$rule['field']])) throw new Forbidden('Scoring properties are not available for this identity.');
            ScoringDefinition::validate($definition,$fields);
            if ($persist) {
                if ($model['status']!=='published' || $this->limit()<1) throw new BadRequest('Publish an entitled scoring model before recording evaluations.');
                if (count($this->all("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND status='published'",$this->scope()))>$this->limit()) throw new BadRequest('Archive excess active models before recording evaluations under this plan.');
                $prior=$this->one('SELECT record_id,result_json FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? AND model_id=? AND request_hash=?',[...$this->scope(),$id,hash('sha256',$input->requestKey)]);
                if ($prior) {
                    if ($prior['record_id']!==$input->recordId) throw new BadRequest('This evaluation key belongs to another record.');
                    return json_decode($prior['result_json'],true,32,JSON_THROW_ON_ERROR)+['replayed'=>true];
                }
            }
            $inputs=[];$asOf=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            foreach ($definition['rules'] as $rule) {
                if (isset($inputs[$rule['field']])) continue;
                $field=$fields[$rule['field']];
                $inputs[$rule['field']]=isset($field['event'])?$this->behaviorCount($record->getId(),$field,$asOf):$record->get($rule['field']);
            }
            $result=ScoringDefinition::evaluate($definition,$fields,$inputs)+['modelId'=>$id,'version'=>(int)$model['version_number'],'definitionHash'=>$model['definition_hash'],'recordId'=>$record->getId(),'entityType'=>$type,'asOf'=>$asOf];
            if ($persist) $this->execute('INSERT INTO nexa_scoring_evaluation (id,tenant_id,service_id,model_id,version_number,record_id,request_hash,score,inputs_json,result_json,input_hash,evaluated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$this->uuid(),...$this->scope(),$id,$model['version_number'],$record->getId(),hash('sha256',$input->requestKey),$result['score'],json_encode($result['inputs'],JSON_THROW_ON_ERROR),json_encode($result,JSON_THROW_ON_ERROR),$result['inputHash'],$this->user->getId()]);
            return $result+['replayed'=>false];
        });
    }

    public function history(string $id,string $recordId): array
    {
        $this->preview($id,(object)['recordId'=>$recordId]);
        $model=$this->model($id);$fields=$this->fields($model['entity_type']);
        $rows=$this->all('SELECT result_json,evaluated_at evaluatedAt,evaluated_by_id evaluatedById FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? AND model_id=? AND record_id=? ORDER BY evaluated_at DESC,id DESC LIMIT 20',[...$this->scope(),$id,$recordId]);
        $history=[];
        foreach ($rows as $row) {
            $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($result['inputs']),array_keys($fields))) throw new Forbidden('Historical scoring properties are not available.');
            $history[]=$result+['evaluatedAt'=>$row['evaluatedAt'],'evaluatedById'=>$row['evaluatedById']];
        }
        return ['evaluations'=>$history];
    }

    private function definition(array $model): array
    {
        if (!hash_equals($model['definition_hash'],hash('sha256',$model['definition_json']))) throw new BadRequest('The scoring definition failed its integrity check.');
        $version=$this->one('SELECT definition_json,definition_hash FROM nexa_scoring_version WHERE tenant_id=? AND service_id=? AND model_id=? AND version_number=?',[...$this->scope(),$model['id'],$model['version_number']]);
        if (($model['status']==='published'&&!$version) || ($version && ($version['definition_json']!==$model['definition_json'] || !hash_equals($version['definition_hash'],$model['definition_hash'])))) throw new BadRequest('The scoring model no longer matches its published snapshot.');
        return json_decode($model['definition_json'],true,32,JSON_THROW_ON_ERROR);
    }

    private function fields(string $type): array
    {
        $result=[];
        foreach (self::FIELDS[$type] as $field) {
            $meta=$this->metadata->get(['entityDefs',$type,'fields',$field],[]);
            if ($meta && $this->acl->checkField($type,$field,'read')) $result[$field]=['type'=>$meta['type'],'options'=>$meta['options']??[]];
        }
        if ($type==='Contact' && $this->acl->checkScope('Contact','read')) {
            foreach (\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::EVENTS as $event=>$source) {
                foreach ([7,30,90,365] as $days) {
                    $key='behavior_'.str_replace('.','_',$event).'_'.$days;
                    $result[$key]=['type'=>'int','options'=>[],'event'=>$event,'source'=>$source,'days'=>$days,'label'=>ucfirst(str_replace('.',' ',$event)).' (last '.$days.' days)'];
                }
            }
        }
        return $result;
    }
    private function behaviorCount(string $contactId,array $field,string $asOf): int
    {
        $row=$this->one("SELECT COUNT(*) total FROM (SELECT DISTINCT source_entity_id FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND event_type=? AND source_entity_type=? AND visibility IN ('internal','team','customer') AND source_entity_id IS NOT NULL AND source_occurred_at BETWEEN DATE_SUB(?,INTERVAL ? DAY) AND ? LIMIT 10001) evidence",[...$this->scope(),$contactId,$field['event'],$field['source'],$asOf,$field['days'],$asOf]);
        $count=(int)$row['total'];
        if ($count>10000) throw new BadRequest('Narrow the behavior window to at most 10,000 distinct events.');
        return $count;
    }
    private function limit(): int
    {
        $row=$this->one("SELECT ps.configuration_json FROM nexa_tenant_subscription s INNER JOIN nexa_plan_definition p ON p.id=s.plan_id AND p.status='active' INNER JOIN nexa_service_definition d ON d.service_key='automation' AND d.status='active' INNER JOIN nexa_plan_service ps ON ps.plan_id=p.id AND ps.service_id=d.id AND ps.is_enabled=1 INNER JOIN nexa_tenant_service g ON g.tenant_id=s.tenant_id AND g.service_id=d.id AND g.status='active' WHERE s.tenant_id=? AND s.status IN ('active','trialing') AND (s.status='active' OR s.trial_ends_at>NOW()) AND (g.starts_at IS NULL OR g.starts_at<=NOW()) AND (g.ends_at IS NULL OR g.ends_at>NOW())",[$this->scope()[0]]);
        if (!$row) return 0;
        $config=json_decode($row['configuration_json']??'{}',true,32,JSON_THROW_ON_ERROR);$limit=$config['scoringModelLimit']??5;
        return in_array($limit,[5,50],true)?$limit:0;
    }
    private function access(bool $manage=false): void { $this->contexts->require();if (!$this->user->get('isActive') || !in_array($this->user->getType(),['admin','regular'],true) || $this->user->get('tenantId')!==$this->scope()[0] || $this->user->get('serviceId')!==$this->scope()[1] || ($manage&&!$this->user->isAdmin())) throw new Forbidden('Scoring access is not allowed.'); }
    private function requireType(string $type): void {if (!$this->acl->checkScope($type,'read')) throw new Forbidden('Record read access is required.');}
    private function model(string $id,bool $lock=false): array {return $this->one('SELECT * FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND id=?'.($lock?' FOR UPDATE':''),[...$this->scope(),$id])??throw new NotFound('Scoring model not found.');}
    private function scope():array {$c=$this->contexts->require();return[$c->tenantId,$c->serviceId];}
    private function lockTenant():void {$this->one('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE',[$this->scope()[0]]);}
    private function all(string $sql,array $args):array {$q=$this->em->getPDO()->prepare($sql);$q->execute($args);return$q->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $args):?array {return$this->all($sql,$args)[0]??null;}
    private function execute(string $sql,array $args):void {$q=$this->em->getPDO()->prepare($sql);$q->execute($args);}
    private function uuid():string {$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);}
    private function transaction(callable $fn):mixed {$pdo=$this->em->getPDO();$tx=$this->em->getTransactionManager();$owns=!$pdo->inTransaction();$save='score_'.bin2hex(random_bytes(5));if($owns)$tx->start();else$pdo->exec('SAVEPOINT '.$save);try{$r=$fn();if($owns)$tx->commit();else$pdo->exec('RELEASE SAVEPOINT '.$save);return$r;}catch(\Throwable $e){if($owns&&$tx->isStarted())$tx->rollback();elseif($pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}throw$e;}}
}
