<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\{Acl, InjectableFactory};
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;

/** Read-only projection over the existing audit ledger, never a second history store. */
final class PersonalizationHistoryService
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts,
        private User $user, private Acl $acl, private ServiceFactory $records, private InjectableFactory $factory) {}

    public function history(array $input): array
    {
        $context = $this->contexts->require();
        if (!$this->user->isAdmin() || !$this->user->isActive() || $this->user->get('tenantId') !== $context->tenantId ||
            $this->user->get('serviceId') !== $context->serviceId) throw new Forbidden('Only the active workspace administrator can review personalization history.');
        if (array_diff(array_keys($input), ['from','to','source','search','cursor'])) throw new BadRequest('Unsupported history filter.');
        $from = $this->date($input['from'] ?? gmdate('Y-m-d', time()-89*86400));
        $to = $this->date($input['to'] ?? gmdate('Y-m-d'));
        if ($from > $to || strtotime($to.' UTC')-strtotime($from.' UTC') > 365*86400) throw new BadRequest('Choose a date range of at most one year.');
        $source = $input['source'] ?? 'all';
        if (!in_array($source, ['all','email','landing'], true)) throw new BadRequest('Choose an available decision source.');
        $search = $input['search'] ?? '';
        if (!is_string($search) || mb_strlen($search)>100) throw new BadRequest('Search must be at most 100 characters.');
        $where = "tenant_id=? AND service_id=? AND occurred_at>=? AND occurred_at<? AND action IN ('personalization.evaluated','personalization.rendered')";
        $args = [$context->tenantId,$context->serviceId,$from.' 00:00:00',gmdate('Y-m-d',strtotime($to.' 00:00:00 UTC +1 day')).' 00:00:00'];
        if ($source !== 'all') {$where .= ' AND action=?';$args[] = $source === 'email' ? 'personalization.evaluated' : 'personalization.rendered';}
        if ($search !== '') {$where .= ' AND (LOCATE(?,id)>0 OR LOCATE(?,subject_id)>0)';$args[]=$search;$args[]=$search;}
        if (array_key_exists('cursor',$input) && $input['cursor']!==null && $input['cursor']!=='') {
            $cursor = is_string($input['cursor']) && strlen($input['cursor'])<=512 ? json_decode(base64_decode($input['cursor'],true) ?: '',true,8) : null;
            if (!is_array($cursor) || !is_string($cursor['id']??null) || !preg_match('/^[a-f0-9-]{36}$/iD',$cursor['id']) ||
                !is_string($cursor['at']??null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D',$cursor['at'])) throw new BadRequest('Invalid history cursor.');
            $parts=explode('.',$cursor['at'],2);$canonical=$parts[0].'.'.str_pad($parts[1]??'',6,'0');
            $clock=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$canonical,new \DateTimeZone('UTC'));
            if(!$clock || $clock->format('Y-m-d H:i:s.u')!==$canonical)throw new BadRequest('Invalid history cursor.');
            $where .= ' AND (occurred_at<? OR (occurred_at=? AND id<?))';array_push($args,$cursor['at'],$cursor['at'],$cursor['id']);
        }
        $query = $this->em->getPDO()->prepare("SELECT id,action,subject_id,subject_type,actor_type,actor_user_id,occurred_at,metadata_json FROM nexa_audit_event WHERE $where ORDER BY occurred_at DESC,id DESC LIMIT 101");
        $query->execute($args);$rows=$query->fetchAll(PDO::FETCH_ASSOC);$hasMore=count($rows)>100;if($hasMore)array_pop($rows);
        $list=[];$catalog=null;$contactCache=[];$actorCache=[];
        foreach($rows as $row) {
            $metadata=strlen((string)$row['metadata_json'])<=65536 ? json_decode((string)$row['metadata_json'],true) : null;
            if (!is_array($metadata)) $metadata=[];
            $actorKey=$row['actor_user_id']??$row['actor_type'];
            $actorCache[$actorKey]??=$this->actor($row['actor_user_id'],$row['actor_type']);
            $item=['id'=>$row['id'],'occurredAt'=>$row['occurred_at'],'clock'=>($metadata['auditClock']??null)==='UTC'?'UTC':'Legacy server time','actor'=>$actorCache[$actorKey], 'details'=>[]];
            if ($row['action']==='personalization.evaluated') {
                if (!$this->acl->checkScope('Contact','read') || !$this->acl->checkScope('MassEmail','read') || !$this->acl->checkScope('EmailTemplate','edit') || !$this->acl->checkField('EmailTemplate','body','edit')) continue;
                if(!array_key_exists($row['subject_id'],$contactCache)) {
                    try {$contactCache[$row['subject_id']]=$this->records->createForUser('Contact',$this->user)->getEntity($row['subject_id']);}
                    catch(Forbidden|NotFound){$contactCache[$row['subject_id']]=null;}
                }
                $contact=$contactCache[$row['subject_id']];if(!$contact)continue;
                if ($contact->get('tenantId')!==$context->tenantId || $contact->get('serviceId')!==$context->serviceId || !$this->acl->checkEntity($contact,'read')) continue;
                $catalog ??= array_column($this->factory->createWith(MarketingConditionalContentService::class,['user'=>$this->user,'acl'=>$this->acl])->catalog()['fields'],'label','name');
                $item += ['source'=>'Email content test','recordType'=>'Contact','recordId'=>$contact->getId(),
                    'recordName'=>$this->acl->checkField('Contact','name','read') && $this->acl->checkField('Contact','firstName','read') && $this->acl->checkField('Contact','lastName','read') ? ($contact->get('name') ?: 'Contact') : 'Contact'];
                $trace=$metadata['conditions']??[];$valid=($metadata['version']??null)===1 && is_array($trace) && count($trace)>=1 && count($trace)<=6 && in_array($metadata['matchMode']??null,['all','any'],true);
                foreach($valid?$trace:[] as $rule) if(!is_array($rule) || !is_string($rule['field']??null) || !isset($catalog[$rule['field']]) || !is_string($rule['operator']??null) || !is_string($rule['value']??'') || !is_bool($rule['matched']??null))$valid=false;
                if($valid) {
                    $outcomes=array_column($trace,'matched');$branch=($metadata['matchMode']==='all'?!in_array(false,$outcomes,true):in_array(true,$outcomes,true))?'matching':'fallback';
                    $valid=($metadata['branch']??null)===$branch;
                    if($valid)foreach($trace as $rule)$item['details'][]=['label'=>$catalog[$rule['field']],'operator'=>$rule['operator']??'', 'expected'=>$rule['value']??'', 'matched'=>$rule['matched']];
                }
                $item['outcome']=$valid?ucfirst($metadata['branch']).' content':'Evidence unavailable';
                $item['verification']=$valid?'Stored condition outcomes':'Restricted or invalid evidence';
            } else {
                if (($metadata['deliveryMode'] ?? null) === 'backend' && ($metadata['contactId'] ?? null)) {
                    if (!$this->acl->checkScope('Contact', 'read')) continue;
                    try {$customer=$this->records->createForUser('Contact',$this->user)->getEntity($metadata['contactId']);}
                    catch (Forbidden|NotFound) {continue;}
                    if (!$customer || $customer->get('deleted') || $customer->get('tenantId')!==$context->tenantId ||
                        $customer->get('serviceId')!==$context->serviceId || !$this->acl->checkEntity($customer,'read')) continue;
                }
                $page=$this->em->getPDO()->prepare('SELECT name FROM nexa_landing_page WHERE id=? AND tenant_id=? AND service_id=?');$page->execute([$row['subject_id'],$context->tenantId,$context->serviceId]);$name=$page->fetchColumn();if($name===false)continue;
                $item += ['source'=>'Published landing page','recordType'=>'NexaLandingPages','recordId'=>null,'recordName'=>$name];
                $snapshot=$this->em->getPDO()->prepare('SELECT configuration_json FROM nexa_landing_page_version WHERE landing_page_id=? AND tenant_id=? AND service_id=? AND version_number=?');
                $snapshot->execute([$row['subject_id'],$context->tenantId,$context->serviceId,(int)($metadata['pageVersion']??0)]);$configuration=json_decode((string)$snapshot->fetchColumn(),true);
                $blocks=array_column($configuration['blocks']??[],null,'id');$trace=$metadata['decisions']??[];
                $valid=($metadata['version']??null)===1 && is_array($trace) && count($trace)>0 && count($trace)<=10;
                foreach($valid?$trace:[] as $rule) {
                    $block=is_array($rule) && is_string($rule['blockId']??null)?($blocks[$rule['blockId']]??null):null;$definition=$block['personalization']??null;
                    if(!is_array($definition) || !is_bool($rule['matched']??null) || !is_string($rule['ruleHash']??null) || !hash_equals(hash('sha256',json_encode($definition,JSON_THROW_ON_ERROR)),$rule['ruleHash']) || ($rule['branch']??null)!==($rule['matched']?'variant':'default')){$valid=false;break;}
                    if (str_starts_with($definition['field']??'', 'customer:') && !$this->customerRuleReadable($definition)) {$valid=false;break;}
                    $item['details'][]=['label'=>$block['id'],'operator'=>$definition['operator']??'equals','expected'=>$definition['value'],'matched'=>$rule['matched'],
                        'heading'=>$rule['matched']?$definition['heading']:($block['heading']??''),'privacyControl'=>(bool)($rule['privacyControl']??false)];
                }
                if(!$valid)$item['details']=[];
                $item['outcome']=$valid?(count(array_filter($trace,fn($rule)=>$rule['matched'])).' variant(s) selected'):'Evidence unavailable';
                $item['verification']=$valid?'Published version '.(int)$metadata['pageVersion']:'Snapshot unavailable';
            }
            $list[]=$item;
        }
        $last=end($rows);
        return ['list'=>$list,'cursor'=>$hasMore&&$last?base64_encode(json_encode(['at'=>$last['occurred_at'],'id'=>$last['id']])):null,
            'scannedCount'=>count($rows),'policy'=>['defaultWindowDays'=>90,'deletion'=>'Managed by the shared audit policy','editable'=>false]];
    }

    private function actor(?string $id, string $type): string
    {
        if(!$id)return $type==='anonymous'?'Anonymous visitor':'System';
        if(!$this->acl->checkScope('User','read'))return 'Staff member';
        try {$user=$this->records->createForUser('User',$this->user)->getEntity($id);}catch(Forbidden|NotFound){return 'Staff member';}
        if(!$user)return 'Staff member';
        $context=$this->contexts->require();
        return $user->get('tenantId')===$context->tenantId && $user->get('serviceId')===$context->serviceId && $this->acl->checkEntity($user,'read') && $this->acl->checkField('User','name','read') && $this->acl->checkField('User','firstName','read') && $this->acl->checkField('User','lastName','read') ? ($user->get('name') ?: 'Staff member') : 'Staff member';
    }

    private function customerRuleReadable(array $rule): bool
    {
        if (!$this->acl->checkScope('Contact','read')) return false;
        $field=substr($rule['field'],9);
        if (in_array($field,['lifecycleStage','leadStatus','leadScore'],true)) return $this->acl->checkField('Contact',$field,'read');
        if ($field==='visits30') return $this->acl->checkScope('BehaviorEvent','read');
        if ($field!=='segment' || !$this->acl->checkScope('TargetList','read')) return false;
        try {$list=$this->records->createForUser('TargetList',$this->user)->getEntity($rule['value']);}
        catch (Forbidden|NotFound) {return false;}
        $context=$this->contexts->require();
        return $list && !$list->get('deleted') && $list->get('tenantId')===$context->tenantId &&
            $list->get('serviceId')===$context->serviceId && $this->acl->checkEntity($list,'read');
    }

    private function date(mixed $value): string
    {
        $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC')):false;
        if(!$date || $date->format('Y-m-d')!==$value || $value<'1970-01-01' || $value>'9998-12-31')throw new BadRequest('Enter a valid history date.');
        return $value;
    }
}
