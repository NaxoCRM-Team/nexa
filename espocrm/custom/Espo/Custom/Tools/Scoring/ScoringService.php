<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Custom\Tools\Customer\CustomerFoundationRecorder;
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Custom\Tools\Customization\CustomizationService;
use Espo\Custom\Tools\Currency\TenantCurrencyService;
use Espo\Core\Currency\{Converter,Rates};
use Espo\Core\Field\Currency;
use PDO;
use stdClass;

final class ScoringService
{
    private const FIELDS=[
        'Contact'=>['contactSource','lifecycleStage','leadStatus','marketingStatus','customerStage','title','department','addressCountry'],
        'Account'=>['industry','type','numberOfEmployees','lifecycleStage','leadStatus','billingAddressCountry','annualRevenue'],
        'Opportunity'=>['stage','probability','leadSource','amount'],
    ];
    public function __construct(private EntityManager $em,private TenantContextStore $contexts,private Metadata $metadata,private User $user,private Acl $acl,private CustomizationService $customization,private InjectableFactory $factory,private AclManager $aclManager) {}

    public function workspace(): array
    {
        $this->access();$fields=[];
        foreach (array_keys(self::FIELDS) as $type) if ($this->acl->checkScope($type,'read')) $fields[$type]=$this->fields($type);
        $models=$this->all('SELECT id,name,entity_type entityType,status,version_number version,modified_at modifiedAt,definition_json,automatic_enabled automaticEnabled,interval_hours intervalHours,next_evaluation_at nextEvaluationAt,last_completed_at lastCompletedAt,last_error_code lastErrorCode,is_primary isPrimary FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? ORDER BY modified_at DESC,id LIMIT 201',$this->scope());
        if (count($models)>200) throw new BadRequest('The stored model limit has been exceeded. Ask the platform administrator to review workspace capacity; archiving preserves stored models.');
        $models=array_values(array_filter($models,function($row)use($fields):bool {
            if (!isset($fields[$row['entityType']])) return false;
            $definition=json_decode($row['definition_json'],true,32,JSON_THROW_ON_ERROR);
            foreach (ScoringDefinition::leaves($definition) as $rule) if (!isset($fields[$row['entityType']][$rule['field']])) return false;
            if (isset($definition['lifecycle']) && !isset($fields[$row['entityType']]['lifecycleStage'])) return false;
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
        $fields=$this->fields($type);
        $definition=json_decode(json_encode($input->definition,JSON_THROW_ON_ERROR),true);
        if (is_array($definition['rules']??null)) foreach ($definition['rules'] as &$rule) {
            if (!is_array($rule)) continue;
            if (isset($rule['conditions']) && is_array($rule['conditions'])) {
                ScoringConditions::bind($rule['conditions'],$fields);
                continue;
            }
            $field=is_string($rule['field']??null)?($fields[$rule['field']]??[]):[];
            if (isset($field['propertyHash'])) $rule['propertyHash']=$field['propertyHash'];
        }
        unset($rule);
        $definition=ScoringDefinition::validate($definition,$fields);
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
        $schedule=($input->action??null)==='schedule';
        $primary=($input->action??null)==='primary';
        if (array_diff(array_keys(get_object_vars($input)),$schedule?['action','enabled','intervalHours']:($primary?['action','enabled']:['action'])) || !in_array($input->action??null,['publish','archive','schedule','primary'],true)) throw new BadRequest('Choose a valid model action.');
        if ($primary && !is_bool($input->enabled??null)) throw new BadRequest('Choose whether this model owns the primary score.');
        if ($schedule && (!is_bool($input->enabled??null) || !in_array($input->intervalHours??null,[1,6,24],true))) throw new BadRequest('Choose an hourly, six-hourly or daily recalculation interval.');
        return $this->transaction(function()use($id,$input):array {
            $this->lockTenant();$model=$this->model($id,true);$this->requireType($model['entity_type']);
            if ($input->action==='primary') {
                if ($model['status']!=='published' || !in_array($model['entity_type'],['Contact','Account'],true)) throw new BadRequest('Only published Contact and Account models can own a primary score.');
                $previousOwner=$this->one('SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND entity_type=? AND is_primary=1',[...$this->scope(),$model['entity_type']]);
                if ($input->enabled) {
                    ScoringDefinition::validate($this->definition($model),$this->fields($model['entity_type']));
                    if ($this->limit()<1 || !$this->acl->checkScope($model['entity_type'],'edit') || !$this->acl->checkField($model['entity_type'],'leadScore','read')) throw new Forbidden('An active scoring entitlement and record edit/score read access are required.');
                    $this->execute('UPDATE nexa_scoring_model SET is_primary=0 WHERE tenant_id=? AND service_id=? AND entity_type=? AND is_primary=1',[...$this->scope(),$model['entity_type']]);
                }
                $this->execute('UPDATE nexa_scoring_model SET is_primary=?,modified_by_id=?,next_evaluation_at=UTC_TIMESTAMP(6),scan_cursor=NULL,scan_until=NULL,scan_key=NULL WHERE tenant_id=? AND service_id=? AND id=?',[$input->enabled?1:0,$this->user->getId(),...$this->scope(),$id]);
                if ((bool)$model['is_primary']!==$input->enabled) $this->factory->createWith(CustomerFoundationRecorder::class,['user'=>$this->user])->scoringOwnershipChanged($model,$input->enabled,$previousOwner['id']??null);
                return ['id'=>$id,'status'=>$model['status']];
            }
            if ($input->action==='schedule') {
                if ($model['status']!=='published') throw new BadRequest('Only published models can be scheduled.');
                if ($input->enabled) {
                    ScoringDefinition::validate($this->definition($model),$this->fields($model['entity_type']));
                    if ($this->limit()<1 || count($this->all("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND status='published'",$this->scope()))>$this->limit()) throw new Forbidden('An active scoring entitlement is required.');
                }
                $this->execute('UPDATE nexa_scoring_model SET automatic_enabled=?,interval_hours=?,execution_user_id=?,next_evaluation_at=UTC_TIMESTAMP(6),scan_cursor=NULL,scan_until=NULL,scan_key=NULL,last_error_code=NULL,modified_by_id=? WHERE tenant_id=? AND service_id=? AND id=?',[$input->enabled?1:0,$input->intervalHours,$this->user->getId(),$this->user->getId(),...$this->scope(),$id]);
                return ['id'=>$id,'status'=>$model['status']];
            }
            if ($input->action==='publish') {
                if ($model['status']!=='draft') throw new BadRequest('Only drafts can be published.');
                ScoringDefinition::validate($this->definition($model),$this->fields($model['entity_type']));
                $count=$this->all("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND status='published'",$this->scope());
                if (count($count)>=$this->limit()) throw new BadRequest('The plan limit for active scoring models has been reached.');
                $this->execute('INSERT INTO nexa_scoring_version (tenant_id,service_id,model_id,version_number,definition_json,definition_hash,published_by_id) VALUES (?,?,?,?,?,?,?)',[...$this->scope(),$id,$model['version_number'],$model['definition_json'],$model['definition_hash'],$this->user->getId()]);
            } elseif ($model['status']==='archived') throw new BadRequest('This model is already archived.');
            $status=$input->action==='publish'?'published':'archived';
            $this->execute('UPDATE nexa_scoring_model SET status=?,modified_by_id=? WHERE tenant_id=? AND service_id=? AND id=?',[$status,$this->user->getId(),...$this->scope(),$id]);
            if ($status==='archived') $this->execute('UPDATE nexa_scoring_model SET automatic_enabled=0,is_primary=0 WHERE tenant_id=? AND service_id=? AND id=?',[...$this->scope(),$id]);
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
            if (in_array($type,['Contact','Account'],true) && !$this->acl->checkField($type,'leadScore','read')) throw new Forbidden('Score read access is required.');
            if ($persist && $model['is_primary']) {
                $table=match($type){'Contact'=>'contact','Account'=>'account',default=>throw new BadRequest('Unsupported primary score subject.')};
                $this->one("SELECT id FROM `$table` WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE",[...$this->scope(),$input->recordId]);
            }
            $record=$this->em->getEntityById($type,$input->recordId);
            if (!$record || $record->get('deleted') || $record->get('tenantId')!==$this->scope()[0] || $record->get('serviceId')!==$this->scope()[1] || !$this->acl->checkEntity($record,'read')) throw new NotFound('Record not found.');
            $definition=$this->definition($model);$fields=$this->fields($type);
            foreach (ScoringDefinition::leaves($definition) as $rule) if (!isset($fields[$rule['field']])) throw new Forbidden('Scoring properties are not available for this identity.');
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
            $inputs=[];$ages=[];$asOf=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $leaves=ScoringDefinition::leaves($definition);$currencySnapshot=null;
            foreach ($leaves as $rule) {
                if (array_key_exists($rule['field'],$inputs)) continue;
                $field=$fields[$rule['field']];
                if (isset($field['event'])) {
                    $evidence=$this->behaviorEvidence($record->getId(),$field,$asOf);
                    $inputs[$rule['field']]=$evidence['count'];$ages[$rule['field']]=$evidence['age'];
                } elseif ($field['type']==='currency') {
                    $currencySnapshot??=$this->factory->createWith(TenantCurrencyService::class,['user'=>$this->user])->get();
                    $targets=array_values(array_unique(array_column(array_filter($leaves,fn($leaf)=>$leaf['field']===$rule['field']),'currency')));
                    $inputs[$rule['field']]=$this->monetaryInput($record,$rule['field'],$targets,$currencySnapshot);
                } else $inputs[$rule['field']]=isset($field['customId'])?$this->customValue($type,$record->getId(),$field):$record->get($rule['field']);
            }
            $result=ScoringDefinition::evaluate($definition,$fields,$inputs,$ages)+['modelId'=>$id,'version'=>(int)$model['version_number'],'definitionHash'=>$model['definition_hash'],'recordId'=>$record->getId(),'entityType'=>$type,'asOf'=>$asOf];
            $adjustments=$this->activeAdjustments($id,$record->getId(),$asOf);$delta=array_sum(array_column($adjustments,'delta'));
            if (abs($delta)>1000000) throw new BadRequest('Active manual adjustments exceed the supported score range.');
            $result['modelScore']=$result['score'];$result['adjustmentTotal']=$delta;$result['adjustmentIds']=array_column($adjustments,'id');
            $result['score']=max(0,min(1000000,$result['modelScore']+$delta));
            foreach ($result['thresholds'] as $threshold) if ($result['score']>=$threshold['min']) $result['band']=$threshold['label'];
            if ($adjustments) $result['inputHash']=hash('sha256',json_encode(['modelInputHash'=>$result['inputHash'],'adjustments'=>$adjustments],JSON_THROW_ON_ERROR));
            $result['lifecycle']=LifecycleScorePolicy::decide($definition['lifecycle']??null,$record->get('lifecycleStage'),$result['score']);
            if ($persist) {
                if ($model['is_primary'] && (!$this->acl->checkEntity($record,'edit') || !$this->acl->checkField($type,'leadScore','read'))) throw new Forbidden('Primary score projection requires record edit and score read access.');
                $promote=$model['is_primary'] && $result['lifecycle']['eligible'];
                if ($promote && !$this->acl->checkField($type,'lifecycleStage','edit')) throw new Forbidden('Lifecycle stage edit access is required.');
                $result['lifecycle']['applied']=(bool)$promote;
                $evaluationId=$this->uuid();
                $result['evaluationId']=$evaluationId;
                $this->execute('INSERT INTO nexa_scoring_evaluation (id,tenant_id,service_id,model_id,version_number,record_id,request_hash,score,inputs_json,result_json,input_hash,evaluated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$evaluationId,...$this->scope(),$id,$model['version_number'],$record->getId(),hash('sha256',$input->requestKey),$result['score'],json_encode($result['inputs'],JSON_THROW_ON_ERROR),json_encode($result,JSON_THROW_ON_ERROR),$result['inputHash'],$this->user->getId()]);
                $scoreChanged=$model['is_primary'] && (int)$record->get('leadScore')!==$result['score'];
                if ($scoreChanged || $promote) {
                    $previous=$record->get('leadScore');if ($scoreChanged) $record->set('leadScore',$result['score']);
                    if ($promote) $record->set('lifecycleStage',$result['lifecycle']['target']);
                    $this->em->saveEntity($record,['nexaPrimaryScoreEvaluation'=>$evaluationId,SaveOption::MODIFIED_BY_ID=>$this->user->getId()]);
                    $recorder=$this->factory->createWith(CustomerFoundationRecorder::class,['user'=>$this->user]);
                    if ($scoreChanged) $recorder->scoreProjected($record,$evaluationId,$model,$result,$previous);
                }
            }
            return $result+['replayed'=>false];
        });
    }

    public function history(string $id,string $recordId): array
    {
        $this->preview($id,(object)['recordId'=>$recordId]);
        $model=$this->model($id);$fields=$this->fields($model['entity_type']);
        $rows=$this->all('SELECT result_json,evaluated_at evaluatedAt,evaluated_by_id evaluatedById FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? AND model_id=? AND record_id=? ORDER BY evaluated_at DESC,id DESC LIMIT 21',[...$this->scope(),$id,$recordId]);
        $hasMoreEvaluations=count($rows)>20;if($hasMoreEvaluations)array_pop($rows);
        $history=[];
        foreach ($rows as $row) {
            $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($result['inputs']),array_keys($fields))) throw new Forbidden('Historical scoring properties are not available.');
            $history[]=$result+['evaluatedAt'=>$row['evaluatedAt'],'evaluatedById'=>$row['evaluatedById']];
        }
        $adjustments=$this->user->isAdmin()?$this->all("SELECT a.id,a.delta,a.reason,a.expires_at expiresAt,a.created_at createdAt,a.created_by_id createdById,a.reverses_id reversesId,CASE WHEN a.reverses_id IS NOT NULL THEN 'reversal' WHEN EXISTS(SELECT 1 FROM nexa_scoring_adjustment r WHERE r.tenant_id=a.tenant_id AND r.service_id=a.service_id AND r.model_id=a.model_id AND r.reverses_id=a.id) THEN 'revoked' WHEN a.expires_at<=UTC_TIMESTAMP(6) THEN 'expired' ELSE 'active' END status FROM nexa_scoring_adjustment a WHERE a.tenant_id=? AND a.service_id=? AND a.model_id=? AND a.record_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT 50",[...$this->scope(),$id,$recordId]):[];
        $count=$this->user->isAdmin()?$this->one('SELECT COUNT(*) total FROM (SELECT id FROM nexa_scoring_adjustment WHERE tenant_id=? AND service_id=? AND model_id=? AND record_id=? LIMIT 51) bounded_adjustments',[...$this->scope(),$id,$recordId]):null;
        return ['evaluations'=>$history,'adjustments'=>$adjustments,'hasMoreEvaluations'=>$hasMoreEvaluations,
            'hasMoreAdjustments'=>(int)($count['total']??0)>50,'historyPolicy'=>['evaluationWindow'=>20,'adjustmentWindow'=>50,'archivingDeletesHistory'=>false]];
    }

    public function adjust(string $id,stdClass $input): array
    {
        $this->access(true);$reverse=isset($input->revokeId);
        $allowed=$reverse?['recordId','requestKey','reason','revokeId']:['recordId','requestKey','reason','delta','expiresAt'];
        if (array_diff(array_keys(get_object_vars($input)),$allowed) || !is_string($input->recordId??null) || !preg_match('/^[a-zA-Z0-9]{1,24}$/D',$input->recordId) || !is_string($input->requestKey??null) || !preg_match('/^[a-zA-Z0-9._:-]{8,128}$/D',$input->requestKey)) throw new BadRequest('Choose a record and stable adjustment request key.');
        if (!is_string($input->reason??null) || mb_strlen(trim($input->reason))<3 || mb_strlen(trim($input->reason))>500) throw new BadRequest('Describe the reason for this adjustment.');
        if ($reverse && (!is_string($input->revokeId) || !preg_match('/^[a-f0-9-]{36}$/D',$input->revokeId))) throw new BadRequest('Choose an adjustment to revoke.');
        if (!$reverse && (!is_int($input->delta??null) || $input->delta===0 || abs($input->delta)>10000)) throw new BadRequest('Enter a non-zero integer adjustment from -10,000 to 10,000.');
        $expiry=null;
        if (!$reverse && ($input->expiresAt??null)!==null) {
            if (!is_string($input->expiresAt)) throw new BadRequest('Enter a valid UTC expiry.');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$input->expiresAt,new \DateTimeZone('UTC'));
            $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s')!==$input->expiresAt) throw new BadRequest('Enter a valid UTC expiry.');
            $expiry=$date->format('Y-m-d H:i:s');
        }
        $request=json_encode(['recordId'=>$input->recordId,'reason'=>trim($input->reason),'delta'=>$reverse?null:$input->delta,'expiresAt'=>$expiry,'revokeId'=>$reverse?$input->revokeId:null],JSON_THROW_ON_ERROR);
        return $this->transaction(function()use($id,$input,$reverse,$expiry,$request):array {
            $this->lockTenant();$model=$this->model($id,true);
            if ($model['status']!=='published' || !$model['is_primary'] || !in_array($model['entity_type'],['Contact','Account'],true)) throw new BadRequest('Manual adjustments require the current primary Contact or Account model.');
            $this->preview($id,(object)['recordId'=>$input->recordId]);
            $record=$this->em->getEntityById($model['entity_type'],$input->recordId);
            if (!$this->acl->checkEntity($record,'edit')) throw new Forbidden('Record edit access is required.');
            $prior=$this->one('SELECT id,request_json,result_json FROM nexa_scoring_adjustment WHERE tenant_id=? AND service_id=? AND model_id=? AND request_hash=?',[...$this->scope(),$id,hash('sha256',$input->requestKey)]);
            if ($prior) {
                if ($prior['request_json']!==$request) throw new BadRequest('This adjustment key belongs to different input.');
                return ['adjustmentId'=>$prior['id'],'result'=>json_decode($prior['result_json'],true,32,JSON_THROW_ON_ERROR),'replayed'=>true];
            }
            if ($expiry!==null) {
                $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
                $date=new \DateTimeImmutable($expiry,new \DateTimeZone('UTC'));
                if ($date<=$now || $date>$now->modify('+365 days')) throw new BadRequest('Expiry must be a future UTC date within one year.');
            }
            $original=null;$delta=$reverse?0:$input->delta;$expires=$expiry;
            if ($reverse) {
                $original=$this->one('SELECT id,delta,expires_at,reverses_id FROM nexa_scoring_adjustment WHERE tenant_id=? AND service_id=? AND model_id=? AND record_id=? AND id=?',[...$this->scope(),$id,$input->recordId,$input->revokeId]);
                if (!$original || $original['reverses_id'] || $this->one('SELECT id FROM nexa_scoring_adjustment WHERE tenant_id=? AND service_id=? AND model_id=? AND reverses_id=?',[...$this->scope(),$id,$input->revokeId])) throw new BadRequest('This adjustment is unavailable or already revoked.');
                $delta=-(int)$original['delta'];$expires=$original['expires_at'];
            }
            $adjustmentId=$this->uuid();
            $this->execute('INSERT INTO nexa_scoring_adjustment (id,tenant_id,service_id,model_id,record_id,request_hash,request_json,delta,reason,expires_at,reverses_id,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$adjustmentId,...$this->scope(),$id,$input->recordId,hash('sha256',$input->requestKey),$request,$delta,trim($input->reason),$expires,$original['id']??null,$this->user->getId()]);
            $result=$this->preview($id,(object)['recordId'=>$input->recordId,'requestKey'=>'adjust:'.$adjustmentId],true);
            $this->execute('UPDATE nexa_scoring_adjustment SET evaluation_id=?,result_json=? WHERE tenant_id=? AND service_id=? AND model_id=? AND id=?',[$result['evaluationId'],json_encode($result,JSON_THROW_ON_ERROR),...$this->scope(),$id,$adjustmentId]);
            $this->factory->createWith(CustomerFoundationRecorder::class,['user'=>$this->user])->scoreAdjustmentRecorded($record,$id,$adjustmentId,$delta,trim($input->reason),$expires,$original['id']??null);
            return ['adjustmentId'=>$adjustmentId,'result'=>$result,'replayed'=>false];
        });
    }

    private function activeAdjustments(string $id,string $recordId,string $asOf): array
    {
        $rows=$this->all('SELECT a.id,a.delta,a.expires_at expiresAt FROM nexa_scoring_adjustment a WHERE a.tenant_id=? AND a.service_id=? AND a.model_id=? AND a.record_id=? AND a.reverses_id IS NULL AND (a.expires_at IS NULL OR a.expires_at>?) AND NOT EXISTS(SELECT 1 FROM nexa_scoring_adjustment r WHERE r.tenant_id=a.tenant_id AND r.service_id=a.service_id AND r.model_id=a.model_id AND r.reverses_id=a.id) ORDER BY a.id LIMIT 1001',[...$this->scope(),$id,$recordId,$asOf]);
        if (count($rows)>1000) throw new BadRequest('Revoke unused adjustments before adding more than 1,000 active adjustments.');
        foreach ($rows as &$row) $row['delta']=(int)$row['delta'];unset($row);return $rows;
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
        $currencySettings=null;
        foreach (self::FIELDS[$type] as $field) {
            $meta=$this->metadata->get(['entityDefs',$type,'fields',$field],[]);
            if ($meta && $this->acl->checkField($type,$field,'read')) $result[$field]=['type'=>$meta['type'],'options'=>$meta['options']??[]];
            if (isset($result[$field]) && $meta['type']==='currency') {
                if (!$this->acl->checkField($type,$field.'Currency','read')) {unset($result[$field]);continue;}
                $currencySettings??=$this->factory->createWith(TenantCurrencyService::class,['user'=>$this->user])->get();
                $result[$field]['currencies']=$currencySettings['enabledCurrencies'];
                $result[$field]['defaultCurrency']=$currencySettings['baseCurrency'];
                $result[$field]['label']=$field==='annualRevenue'?'Annual revenue':'Opportunity amount';
            }
        }
        if (in_array($type,['Contact','Account'],true)) {
            foreach ($this->customization->definitions($type)['fields'] as $field) {
                if (!$field['is_enabled'] || !in_array($field['data_type'],['text','single_select','boolean','number'],true)) continue;
                $key='custom:'.$field['field_key'];
                if (!$this->acl->checkField($type,$field['field_key'],'read')) continue;
                $result[$key]=[
                    'type'=>match($field['data_type']){'number'=>'decimal','single_select','boolean'=>'enum',default=>'varchar'},
                    'options'=>$field['data_type']==='boolean'?['true','false']:$field['options'],
                    'label'=>$field['label'].' (custom)', 'customId'=>$field['id'], 'dataType'=>$field['data_type'],
                    'defaultValue'=>$field['defaultValue'],
                    'propertyHash'=>hash('sha256',json_encode([$field['id'],$field['data_type'],$field['options'],$field['defaultValue'],$field['validation']],JSON_THROW_ON_ERROR)),
                ];
            }
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
    private function monetaryInput(\Espo\ORM\Entity $record,string $field,array $targets,array $settings): ?array
    {
        $amount=$record->get($field);if ($amount===null || $amount==='') return null;
        $code=$record->get($field.'Currency');
        if (!is_string($code) || !in_array($code,$settings['enabledCurrencies'],true)) throw new BadRequest('The record currency is unavailable in this workspace.');
        \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DecimalComparison::canonical((string)$amount);
        $rates=$settings['rates'];$normalized=[];
        foreach (array_unique([$code,...$targets]) as $currency) if (!isset($rates[$currency]) || !is_numeric($rates[$currency]) || !is_finite((float)$rates[$currency]) || $rates[$currency]<=0) throw new BadRequest('A required workspace conversion rate is unavailable.');
        $converter=$this->factory->create(Converter::class);
        foreach ($targets as $target) {
            if ($target===$code) {$normalized[$target]=(string)$amount;continue;}
            if (!function_exists('bcmul') || !function_exists('bcdiv')) throw new BadRequest('Precise currency scoring requires the PHP BCMath extension.');
            $normalized[$target]=$converter->convertWithRates(new Currency((string)$amount,$code),$target,Rates::fromAssoc($rates,$settings['baseCurrency']))->getAmountAsString();
        }
        ksort($normalized);$usedRates=array_intersect_key($rates,array_fill_keys([$code,...$targets],true));ksort($usedRates);
        return ['amount'=>(string)$amount,'code'=>$code,'normalized'=>$normalized,'baseCurrency'=>$settings['baseCurrency'],'rates'=>$usedRates,'effectiveDate'=>$settings['ratesEffectiveDate'],'rateMode'=>$settings['rateMode'],'rateProvider'=>$settings['rateProvider']];
    }
    private function customValue(string $type,string $recordId,array $field): mixed
    {
        // Keep stored decimals as strings; the generic display API casts them to floats.
        $row=$this->one('SELECT value_text,value_number,value_boolean FROM nexa_custom_field_value WHERE tenant_id=? AND service_id=? AND entity_type=? AND entity_id=? AND field_definition_id=?',[...$this->scope(),$type,$recordId,$field['customId']]);
        $value=$row?match($field['dataType']){'number'=>$row['value_number'],'boolean'=>$row['value_boolean']===null?null:(bool)$row['value_boolean'],default=>$row['value_text']}:$field['defaultValue'];
        return $field['dataType']==='boolean' && $value!==null?($value?'true':'false'):$value;
    }
    private function behaviorEvidence(string $contactId,array $field,string $asOf): array
    {
        $row=$this->one("SELECT COUNT(*) total,MAX(last_at) last_at FROM (SELECT source_entity_id,MAX(source_occurred_at) last_at FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND event_type=? AND source_entity_type=? AND visibility IN ('internal','team','customer') AND source_entity_id IS NOT NULL AND source_occurred_at BETWEEN DATE_SUB(?,INTERVAL ? DAY) AND ? GROUP BY source_entity_id LIMIT 10001) evidence",[...$this->scope(),$contactId,$field['event'],$field['source'],$asOf,$field['days'],$asOf]);
        $count=(int)$row['total'];
        if ($count>10000) throw new BadRequest('Narrow the behavior window to at most 10,000 distinct events.');
        $days=$row['last_at']===null?null:(int)floor(((new \DateTimeImmutable($asOf,new \DateTimeZone('UTC')))->getTimestamp()-(new \DateTimeImmutable($row['last_at'],new \DateTimeZone('UTC')))->getTimestamp())/86400);
        return ['count'=>$count,'age'=>['days'=>$days,'lastEventAt'=>$row['last_at']]];
    }
    public function processDue(): int
    {
        if (!$this->user->isSystem()) throw new Forbidden('Scheduled scoring is a background-job operation.');
        $candidates=$this->all("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND status='published' AND automatic_enabled=1 AND next_evaluation_at<=UTC_TIMESTAMP(6) ORDER BY next_evaluation_at,id LIMIT 5",$this->scope());
        $processed=0;
        foreach ($candidates as $candidate) $processed+=$this->transaction(function()use($candidate):int {
            // Serialize schedule changes, archiving and competing workers in the same lock order.
            $this->lockTenant();$model=$this->model($candidate['id'],true);
            $now=$this->one('SELECT UTC_TIMESTAMP(6) now',[])['now'];
            if (!$model['automatic_enabled'] || $model['status']!=='published' || $model['next_evaluation_at']>$now) return 0;
            if ($this->limit()<1 || count($this->all("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND status='published'",$this->scope()))>$this->limit()) {
                $this->execute("UPDATE nexa_scoring_model SET next_evaluation_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR),last_error_code='entitlement_unavailable' WHERE tenant_id=? AND service_id=? AND id=?",[...$this->scope(),$model['id']]);return 0;
            }
            $actor=$model['execution_user_id']?$this->em->getEntityById('User',$model['execution_user_id']):null;
            if (!$actor instanceof User || !$actor->isActive() || $actor->get('deleted') || !$actor->isAdmin() || $actor->get('tenantId')!==$this->scope()[0] || $actor->get('serviceId')!==$this->scope()[1]) {
                $this->stopSchedule($model['id'],'execution_identity_unavailable');return 0;
            }
            $acl=$this->aclManager->createUserAcl($actor);
            $service=$this->factory->createWith(self::class,['user'=>$actor,'acl'=>$acl]);
            try {
                $service->requireType($model['entity_type']);
                ScoringDefinition::validate($service->definition($model),$service->fields($model['entity_type']));
                $table=match($model['entity_type']){'Contact'=>'contact','Account'=>'account','Opportunity'=>'opportunity',default=>throw new BadRequest('Unsupported scoring subject.')};
                $until=$model['scan_until'];$key=$model['scan_key'];$cursor=$model['scan_cursor']??'';
                if (!$key) {$key=$this->uuid();$until=$this->one("SELECT MAX(id) id FROM `$table` WHERE tenant_id=? AND service_id=? AND deleted=0",$this->scope())['id'];}
                $rows=$until?$this->all("SELECT id FROM `$table` WHERE tenant_id=? AND service_id=? AND deleted=0 AND id>? AND id<=? ORDER BY id LIMIT 11",[...$this->scope(),$cursor,$until]):[];
                $more=count($rows)>10;$count=0;
                foreach (array_slice($rows,0,10) as $row) {
                    try {$service->preview($model['id'],(object)['recordId'=>$row['id'],'requestKey'=>'auto:'.hash('sha256',$key.':'.$row['id'])],true);$count++;} catch (NotFound $error) { /* Deleted or moved records do not block a scan. */ }
                    $cursor=$row['id'];
                }
                $this->execute('UPDATE nexa_scoring_model SET scan_cursor=?,scan_until=?,scan_key=?,next_evaluation_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),last_completed_at=IF(?=1,last_completed_at,UTC_TIMESTAMP(6)),last_error_code=NULL WHERE tenant_id=? AND service_id=? AND id=?',[$more?$cursor:null,$more?$until:null,$more?$key:null,$more?60:((int)$model['interval_hours']*3600),$more?1:0,...$this->scope(),$model['id']]);
                return $count;
            } catch (Forbidden|BadRequest $error) {$this->stopSchedule($model['id'],'configuration_or_access_invalid');return 0;}
        });
        return $processed;
    }
    private function stopSchedule(string $id,string $code): void
    {
        $this->execute('UPDATE nexa_scoring_model SET automatic_enabled=0,last_error_code=? WHERE tenant_id=? AND service_id=? AND id=?',[$code,...$this->scope(),$id]);
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
