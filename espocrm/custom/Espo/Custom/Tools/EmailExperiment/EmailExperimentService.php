<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\EmailExperiment;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\{User, EmailTemplate};
use Espo\Modules\Crm\Entities\MassEmail;
use Espo\Custom\Tools\MarketingEmail\{MarketingDispatchService, MarketingEmailWorkspaceService, MarketingContentIntegrity};
use PDO;
use stdClass;

/** Persisted A/B orchestration around governed native queues, never a second sender. */
final class EmailExperimentService
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts,
        private User $user, private Acl $acl, private AclManager $aclManager, private InjectableFactory $factory,
        private MarketingContentIntegrity $integrity) {}

    public function catalog(string $search='',int $offset=0,string $sort='createdAt',string $direction='desc'): array
    {
        $this->access();
        if(mb_strlen($search)>200 || $offset<0 || $offset>1000000)throw new BadRequest('Search text or scrolling offset is invalid.');
        $columns=['createdAt'=>'created_at','name'=>'name','status'=>'status'];
        if(!isset($columns[$sort])||!in_array($direction,['asc','desc'],true))throw new BadRequest('Choose a supported catalogue sort order.');
        $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($search)).'%';
        $filter=" WHERE tenant_id=? AND service_id=? AND (name LIKE ? ESCAPE '!' OR status LIKE ? ESCAPE '!')";
        $args=[...$this->scope(),$term,$term];
        $experiments = $this->all('SELECT id,name,status,test_percent AS testPercent,holdout_percent AS holdoutPercent,window_minutes AS windowMinutes,winner_variant AS winner,decision_due_at AS decisionDueAt,last_error AS lastError,created_at AS createdAt FROM nexa_email_experiment'.$filter.' ORDER BY '.$columns[$sort].' '.strtoupper($direction).',id DESC LIMIT 200 OFFSET '.$offset, $args);
        $total=(int)$this->one('SELECT COUNT(*) count FROM nexa_email_experiment'.$filter,$args)['count'];
        $sources = [];
        foreach ($this->all("SELECT p.id,p.version_number FROM nexa_marketing_email_profile p WHERE p.tenant_id=? AND p.service_id=? AND p.delivery_mode='campaign' AND p.lifecycle_status='approved' ORDER BY p.created_at DESC LIMIT 200", $this->scope()) as $row) {
            try {
                $source = $this->source($row['id'], (int) $row['version_number']);
                $sources[] = ['id'=>$source['id'], 'name'=>$source['name'], 'version'=>(int) $source['version_number'], 'campaignId'=>$source['campaign_id'], 'purposeId'=>$source['purpose_id']];
            } catch (BadRequest|Forbidden|NotFound) {continue;}
        }
        return ['experiments'=>$experiments, 'sources'=>$sources,'total'=>$total,'nextOffset'=>$offset+count($experiments),'hasMore'=>$offset+count($experiments)<$total];
    }

    public function create(stdClass $input): array
    {
        $this->access();
        $allowed = ['name','sourceA','versionA','sourceB','versionB','testPercent','holdoutPercent','windowMinutes'];
        if (array_diff(array_keys(get_object_vars($input)), $allowed) || !is_string($input->name ?? null) || trim($input->name)==='' || mb_strlen($input->name)>160 || !is_string($input->sourceA ?? null) || !is_string($input->sourceB ?? null)) throw new BadRequest('Provide an experiment name and two approved email variants.');
        foreach (['versionA','versionB','testPercent','holdoutPercent','windowMinutes'] as $field) if (!is_int($input->$field ?? null)) throw new BadRequest('Choose whole-number versions, audience percentages and a test window.');
        if ($input->sourceA === $input->sourceB) throw new BadRequest('Choose two different approved emails.');
        if ($input->windowMinutes<1 || $input->windowMinutes>10080) throw new BadRequest('Choose a test window from 1 minute to 7 days.');
        ExperimentAssignment::assign([['contact_id'=>'validation-a','email'=>'a@example.test'],['contact_id'=>'validation-b','email'=>'b@example.test']], 'validation', $input->testPercent, $input->holdoutPercent);
        return $this->transaction(function () use ($input): array {
            $this->lockTenant();
            $sources = ['A'=>$this->source($input->sourceA,$input->versionA,true),'B'=>$this->source($input->sourceB,$input->versionB,true)];
            if ($sources['A']['campaign_id']!==$sources['B']['campaign_id'] || $sources['A']['purpose_id']!==$sources['B']['purpose_id']) throw new BadRequest('Both variants must use the same active campaign and consent purpose.');
            $id = $this->uuid();
            $this->execute("INSERT INTO nexa_email_experiment (id,tenant_id,service_id,name,campaign_id,purpose_id,test_percent,holdout_percent,window_minutes,assignment_seed,execution_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$id,...$this->scope(),trim($input->name),$sources['A']['campaign_id'],$sources['A']['purpose_id'],$input->testPercent,$input->holdoutPercent,$input->windowMinutes,bin2hex(random_bytes(32)),$this->user->getId()]);
            foreach ($sources as $variant=>$source) {
                $snapshot = $this->snapshot($source);
                $this->execute('INSERT INTO nexa_email_experiment_variant (tenant_id,service_id,experiment_id,variant_key,source_profile_id,source_version,snapshot_json,snapshot_sha256) VALUES (?,?,?,?,?,?,?,?)', [...$this->scope(),$id,$variant,$source['id'],$source['version_number'],$snapshot,hash('sha256',$snapshot)]);
            }
            $this->event($id,'created',['sources'=>[$input->sourceA,$input->sourceB]]);
            return ['id'=>$id,'status'=>'draft'];
        });
    }

    public function get(string $id): array
    {
        $this->access(); $experiment=$this->experiment($id);
        $variants=$this->variants($id);
        $metrics=[];
        foreach ($variants as $variant) {
            $key=$variant['variant_key'];
            $metric=$this->one("SELECT COUNT(*) assigned,SUM(a.status='queued') queued,SUM(a.status='suppressed') suppressed,SUM(d.status='delivered') delivered,SUM(d.status IN ('bounced','complained','failed')) failed FROM nexa_email_experiment_assignment a LEFT JOIN nexa_marketing_delivery_message d ON d.email_queue_item_id=a.queue_item_id AND d.tenant_id=a.tenant_id AND d.service_id=a.service_id WHERE a.tenant_id=? AND a.service_id=? AND a.experiment_id=? AND a.cohort='test' AND a.variant_key=?", [...$this->scope(),$id,$key]) ?? [];
            $events=$this->one("SELECT COUNT(DISTINCT CASE WHEN e.event_type='clicked' THEN a.contact_id END) uniqueClicks,COUNT(DISTINCT CASE WHEN e.event_type='opened' THEN a.contact_id END) recordedOpens FROM nexa_email_experiment_assignment a INNER JOIN nexa_marketing_delivery_event e ON e.profile_id=a.send_profile_id AND e.tenant_id=a.tenant_id AND e.service_id=a.service_id WHERE a.tenant_id=? AND a.service_id=? AND a.experiment_id=? AND a.cohort='test' AND a.variant_key=? AND e.occurred_at>=? AND (? IS NULL OR e.occurred_at<=?)", [...$this->scope(),$id,$key,$experiment['started_at'],$experiment['decision_due_at'],$experiment['decision_due_at']]) ?? [];
            $metrics[$key]=array_map('intval',$metric+$events);
            // Opens can be automated by privacy proxies; no automatic open-based winner.
            $metrics[$key]['uniqueClickRate']=$metrics[$key]['queued'] ? round(100*$metrics[$key]['uniqueClicks']/$metrics[$key]['queued'],2) : 0;
            unset($variant['snapshot_json']);
        }
        $safeVariants=array_map(fn(array $row): array=>['key'=>$row['variant_key'],'sourceId'=>$row['source_profile_id'],'version'=>(int)$row['source_version'],'snapshotHash'=>$row['snapshot_sha256']],$variants);
        $cohorts=$this->all('SELECT cohort,status,COUNT(*) count FROM nexa_email_experiment_assignment WHERE tenant_id=? AND service_id=? AND experiment_id=? GROUP BY cohort,status', [...$this->scope(),$id]);
        $history=$this->all('SELECT event_type AS type,actor_user_id AS actorId,payload_json AS payload,occurred_at AS occurredAt FROM nexa_email_experiment_event WHERE tenant_id=? AND service_id=? AND experiment_id=? ORDER BY occurred_at DESC,id DESC LIMIT 100', [...$this->scope(),$id]);
        unset($experiment['assignment_seed']);
        return ['experiment'=>$experiment,'variants'=>$safeVariants,'metrics'=>$metrics,'cohorts'=>$cohorts,'history'=>$history];
    }

    public function control(string $id,stdClass $input): array
    {
        $this->access();
        if (!is_string($input->action ?? null) || array_diff(array_keys(get_object_vars($input)),['action','variant','reason'])) throw new BadRequest('Choose an experiment action.');
        return $this->transaction(function () use ($id,$input): array {
            $this->lockTenant(); $experiment=$this->experiment($id,true); $action=$input->action;
            if ($action==='start') {
                if ($experiment['status']!=='draft') throw new BadRequest('Only a draft experiment can be started.');
                $variants=$this->validateVariants($id);
                foreach ($variants as $variant) {
                    $exposure=$this->one('SELECT COUNT(*) count FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND source_profile_id=? AND source_version=?', [...$this->scope(),$variant['source_profile_id'],$variant['source_version']]);
                    $source=$this->source($variant['source_profile_id'],(int)$variant['source_version'],true);
                    $native=$this->one('SELECT COUNT(*) count FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND deleted=0 AND is_test=0', [...$this->scope(),$source['mass_email_id']]);
                    $owned=$this->one('SELECT COUNT(*) count FROM nexa_email_experiment_variant v INNER JOIN nexa_email_experiment x ON x.id=v.experiment_id AND x.tenant_id=v.tenant_id AND x.service_id=v.service_id WHERE v.tenant_id=? AND v.service_id=? AND v.source_profile_id=? AND v.source_version=? AND x.started_at IS NOT NULL AND x.id<>?', [...$this->scope(),$variant['source_profile_id'],$variant['source_version'],$id]);
                    if ((int)$exposure['count'] || (int)$native['count'] || (int)$owned['count']) throw new BadRequest('A variant already has an audience exposure. Create and approve a fresh email variant.');
                }
                $audience=$this->dispatch($this->user)->experimentAudience($variants[0]['source_profile_id']);
                if (count($audience)>100000) throw new BadRequest('Narrow the campaign to at most 100,000 eligible recipients per experiment.');
                $audience=array_map(fn(array $row):array=>['contact_id'=>$row['id'],'email'=>$row['email']],$audience);
                $assignments=ExperimentAssignment::assign($audience,$experiment['assignment_seed'],(int)$experiment['test_percent'],(int)$experiment['holdout_percent']);
                foreach ($assignments as $row) $this->execute('INSERT INTO nexa_email_experiment_assignment (tenant_id,service_id,experiment_id,contact_id,recipient_sha256,cohort,variant_key) VALUES (?,?,?,?,?,?,?)', [...$this->scope(),$id,$row['contactId'],$row['recipientHash'],$row['cohort'],$row['variant']]);
                $this->execute("UPDATE nexa_email_experiment SET status='testing',execution_user_id=?,started_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND id=?", [$this->user->getId(),...$this->scope(),$id]);
                $this->event($id,'started',['audience'=>count($assignments),'testPercent'=>(int)$experiment['test_percent'],'holdoutPercent'=>(int)$experiment['holdout_percent']]);
            } elseif ($action==='winner') {
                if (!in_array($experiment['status'],['testing','awaiting_winner'],true) || !$experiment['decision_due_at'] || strtotime($experiment['decision_due_at'].' UTC')>time()) throw new BadRequest('Finish the test audience and wait for the test window before choosing a winner.');
                if (!in_array($input->variant ?? null,['A','B'],true) || !is_string($input->reason ?? null) || mb_strlen(trim($input->reason))<3 || mb_strlen($input->reason)>1000) throw new BadRequest('Choose variant A or B and record a decision reason.');
                $this->validateVariants($id);
                $this->execute("UPDATE nexa_email_experiment SET status='rollout',winner_variant=?,winner_reason=?,execution_user_id=?,last_error=NULL WHERE tenant_id=? AND service_id=? AND id=?", [$input->variant,trim($input->reason),$this->user->getId(),...$this->scope(),$id]);
                $this->execute("UPDATE nexa_email_experiment_assignment SET variant_key=? WHERE tenant_id=? AND service_id=? AND experiment_id=? AND cohort='rollout' AND status='pending'", [$input->variant,...$this->scope(),$id]);
                $this->event($id,'winner_selected',['variant'=>$input->variant,'reason'=>trim($input->reason),'metrics'=>$this->get($id)['metrics']]);
            } elseif ($action==='cancel') {
                if (in_array($experiment['status'],['completed','cancelled'],true)) throw new BadRequest('The experiment is already closed.');
                $this->execute("UPDATE nexa_email_experiment SET status='cancelled',completed_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND id=?", [...$this->scope(),$id]);
                $this->execute("UPDATE nexa_email_experiment_assignment SET status='cancelled' WHERE tenant_id=? AND service_id=? AND experiment_id=? AND status='pending'", [...$this->scope(),$id]);
                $cancelled=0;
                $queued=$this->all("SELECT a.contact_id,a.queue_item_id,p.id,p.mass_email_id FROM nexa_email_experiment_assignment a INNER JOIN nexa_marketing_email_profile p ON p.id=a.send_profile_id AND p.tenant_id=a.tenant_id AND p.service_id=a.service_id WHERE a.tenant_id=? AND a.service_id=? AND a.experiment_id=? AND a.status='queued' ORDER BY p.id FOR UPDATE",[...$this->scope(),$id]);
                foreach($queued as $item){
                    $query=$this->em->getPDO()->prepare("UPDATE email_queue_item SET status='Failed' WHERE tenant_id=? AND service_id=? AND id=? AND status='Pending' AND deleted=0");$query->execute([...$this->scope(),$item['queue_item_id']]);
                    if(!$query->rowCount())continue;
                    $cancelled++;
                    $this->execute("UPDATE nexa_marketing_email_profile SET lifecycle_status='paused' WHERE tenant_id=? AND service_id=? AND id=? AND lifecycle_status='sending'",[...$this->scope(),$item['id']]);
                    $this->execute("UPDATE nexa_email_experiment_assignment SET status='cancelled',error_reason='experiment_cancelled_before_submission' WHERE tenant_id=? AND service_id=? AND experiment_id=? AND contact_id=?",[...$this->scope(),$id,$item['contact_id']]);
                }
                $this->event($id,'cancelled',['cancelledPendingQueues'=>$cancelled,'submittedMessagesCannotBeRecalled'=>true]);
            } elseif ($action==='pause') {
                if (!in_array($experiment['status'],['testing','rollout'],true)) throw new BadRequest('Only a running experiment can be paused.');
                $this->execute("UPDATE nexa_email_experiment SET status='paused' WHERE tenant_id=? AND service_id=? AND id=?", [...$this->scope(),$id]); $this->event($id,'paused',[]);
            } elseif ($action==='resume') {
                if ($experiment['status']!=='paused') throw new BadRequest('Only a paused experiment can be resumed.');
                $this->validateVariants($id);
                $status=$experiment['winner_variant']?'rollout':'testing';
                $this->execute('UPDATE nexa_email_experiment SET status=?,execution_user_id=?,last_error=NULL WHERE tenant_id=? AND service_id=? AND id=?', [$status,$this->user->getId(),...$this->scope(),$id]); $this->event($id,'resumed',[]);
            } else throw new BadRequest('Choose start, pause, resume, cancel or winner.');
            return ['id'=>$id,'status'=>$this->experiment($id)['status']];
        });
    }

    /** A scoped worker queues at most ten recipients per experiment per pass. */
    public function runScheduled(): void
    {
        $ids=$this->all("SELECT id FROM nexa_email_experiment WHERE tenant_id=? AND service_id=? AND status IN ('testing','rollout') ORDER BY modified_at,id LIMIT 5",$this->scope());
        foreach ($ids as $row) {
            try {$this->advance($row['id']);}
            catch (\Throwable $error) {
                $this->transaction(function () use ($row,$error):void {
                    $this->lockTenant(); $experiment=$this->experiment($row['id'],true);
                    if (!in_array($experiment['status'],['testing','rollout'],true)) return;
                    $message=mb_substr($error->getMessage(),0,1000);
                    $this->execute("UPDATE nexa_email_experiment SET status='paused',last_error=? WHERE tenant_id=? AND service_id=? AND id=?",[$message,...$this->scope(),$row['id']]);
                    $this->event($row['id'],'execution_paused',['reason'=>$message]);
                });
            }
        }
    }

    private function advance(string $id): void
    {
        $this->transaction(function () use ($id):void {
            $this->lockTenant(); $experiment=$this->experiment($id,true);
            if (!in_array($experiment['status'],['testing','rollout'],true)) return;
            $actor=$this->em->getEntityById('User',$experiment['execution_user_id']);
            if (!$actor instanceof User || !$actor->isAdmin() || !$actor->get('isActive') || $actor->get('tenantId')!==$this->scope()[0] || $actor->get('serviceId')!==$this->scope()[1]) throw new Forbidden('The experiment execution administrator is no longer available.');
            $acl=$this->aclManager->createUserAcl($actor);
            $service=$this->factory->createWith(self::class,['user'=>$actor,'acl'=>$acl]);
            $service->access(); $variants=$service->validateVariants($id);
            $dispatch=$this->dispatch($actor); $variantMap=[];
            foreach ($variants as $variant) $variantMap[$variant['variant_key']]=$variant;
            $eligible=[];
            foreach ($dispatch->experimentAudience($variants[0]['source_profile_id']) as $contact) $eligible[$contact['id']]=true;
            $cohort=$experiment['status']==='testing'?'test':'rollout';
            $rows=$this->all("SELECT * FROM nexa_email_experiment_assignment WHERE tenant_id=? AND service_id=? AND experiment_id=? AND cohort=? AND status='pending' ORDER BY contact_id LIMIT 10 FOR UPDATE", [...$this->scope(),$id,$cohort]);
            foreach ($rows as $assignment) {
                // Preserve the recipient captured at enrollment: changing an email cannot expose a new address.
                $recipient=$this->one("SELECT ea.name FROM contact c INNER JOIN entity_email_address eea ON eea.entity_id=c.id AND eea.entity_type='Contact' AND eea.`primary`=1 AND eea.deleted=0 AND eea.tenant_id=c.tenant_id AND eea.service_id=c.service_id INNER JOIN email_address ea ON ea.id=eea.email_address_id AND ea.tenant_id=c.tenant_id AND ea.service_id=c.service_id WHERE c.id=? AND c.tenant_id=? AND c.service_id=? AND c.deleted=0", [$assignment['contact_id'],...$this->scope()]);
                if (!isset($eligible[$assignment['contact_id']]) || !$recipient || !hash_equals($assignment['recipient_sha256'],hash('sha256',mb_strtolower(trim($recipient['name']))))) {
                    $this->execute("UPDATE nexa_email_experiment_assignment SET status='suppressed',error_reason='recipient_changed_or_ineligible' WHERE tenant_id=? AND service_id=? AND experiment_id=? AND contact_id=?",[...$this->scope(),$id,$assignment['contact_id']]); continue;
                }
                $variant=$variantMap[$assignment['variant_key']];
                $result=$dispatch->reusableSend($variant['source_profile_id'],$assignment['contact_id'],'experiment:'.$id.':'.$assignment['contact_id'],(int)$variant['source_version'],new stdClass());
                $this->execute("UPDATE nexa_email_experiment_assignment SET status='queued',queue_item_id=?,send_profile_id=?,queued_at=UTC_TIMESTAMP(6),error_reason=NULL WHERE tenant_id=? AND service_id=? AND experiment_id=? AND contact_id=? AND status='pending'", [$result['queueItemId'],$result['id'] ?? $result['profileId'],...$this->scope(),$id,$assignment['contact_id']]);
            }
            $remaining=$this->one("SELECT COUNT(*) count FROM nexa_email_experiment_assignment WHERE tenant_id=? AND service_id=? AND experiment_id=? AND cohort=? AND status='pending'", [...$this->scope(),$id,$cohort]);
            if (!(int)$remaining['count']) {
                if ($cohort==='test') {
                    $this->execute("UPDATE nexa_email_experiment SET status='awaiting_winner',test_completed_at=UTC_TIMESTAMP(6),decision_due_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL window_minutes MINUTE) WHERE tenant_id=? AND service_id=? AND id=? AND test_completed_at IS NULL", [...$this->scope(),$id]);
                    // Resuming an exhausted test preserves its original decision deadline.
                    $this->execute("UPDATE nexa_email_experiment SET status='awaiting_winner' WHERE tenant_id=? AND service_id=? AND id=? AND status='testing'",[...$this->scope(),$id]);
                } else $this->execute("UPDATE nexa_email_experiment SET status='completed',completed_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND id=?", [...$this->scope(),$id]);
                $this->event($id,$cohort==='test'?'test_queued':'rollout_queued',[],$actor->getId());
            }
            $this->execute('UPDATE nexa_email_experiment SET modified_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND id=?',[...$this->scope(),$id]);
        });
    }

    private function source(string $id,int $version,bool $lock=false): array
    {
        $row=$this->one("SELECT p.*,m.name,m.email_template_id,m.campaign_id,m.from_address,m.from_name,m.reply_to_address,m.reply_to_name,cp.workflow_status campaign_status FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id AND m.deleted=0 INNER JOIN nexa_campaign_profile cp ON cp.campaign_id=m.campaign_id AND cp.tenant_id=p.tenant_id AND cp.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND p.id=?".($lock?' FOR UPDATE':''),[...$this->scope(),$id]);
        if (!$row) throw new NotFound('Approved email variant not found.');
        $mass=$this->em->getEntityById('MassEmail',$row['mass_email_id']); $template=$this->em->getEntityById('EmailTemplate',$row['email_template_id']);
        if (!$mass instanceof MassEmail || !$template instanceof EmailTemplate || !$this->acl->checkEntity($mass,'edit') || !$this->acl->checkEntity($template,'read')) throw new Forbidden('The variant is not available.');
        if ($row['delivery_mode']!=='campaign' || $row['lifecycle_status']!=='approved' || (int)$row['version_number']!==$version || $row['campaign_status']!=='active') throw new BadRequest('Use the current approved marketing variant in an active campaign.');
        $this->integrity->requireSnapshot($row,$template,$mass);
        $snapshot=$this->savedContent($id,$version);
        if (str_contains($snapshot['subject'].' '.$snapshot['bodyHtml'].' '.($snapshot['preheader']??''),'[[nexa:')) throw new BadRequest('Experiment variants cannot require runtime parameters. Use approved personalized Contact properties instead.');
        return $row;
    }

    private function snapshot(array $source): string
    {
        $saved=$this->savedContent($source['id'],(int)$source['version_number']);
        return json_encode(['sourceId'=>$source['id'],'version'=>(int)$source['version_number'],'subject'=>$saved['subject'],'bodyHtml'=>$saved['bodyHtml'],'preheader'=>$saved['preheader']??'', 'campaignId'=>$source['campaign_id'],'purposeId'=>$source['purpose_id'],'fromAddress'=>$source['from_address'],'fromName'=>$source['from_name'],'replyToAddress'=>$source['reply_to_address'],'replyToName'=>$source['reply_to_name']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    private function savedContent(string $id,int $version):array
    {
        return $this->one('SELECT subject,body_html AS bodyHtml,preheader FROM nexa_marketing_email_version WHERE tenant_id=? AND service_id=? AND profile_id=? AND version_number=?',[...$this->scope(),$id,$version]) ?? throw new BadRequest('Approved variant content is unavailable.');
    }
    private function validateVariants(string $id): array
    {
        $variants=$this->variants($id);
        if (count($variants)!==2) throw new BadRequest('Both immutable experiment variants are required.');
        foreach ($variants as $variant) {
            $source=$this->source($variant['source_profile_id'],(int)$variant['source_version'],true);
            if (!hash_equals($variant['snapshot_sha256'],hash('sha256',$variant['snapshot_json'])) || !hash_equals($variant['snapshot_sha256'],hash('sha256',$this->snapshot($source)))) throw new BadRequest('An experiment variant changed after its snapshot. Create a fresh experiment.');
        }
        return $variants;
    }
    private function dispatch(User $actor): MarketingDispatchService
    {
        $acl=$this->aclManager->createUserAcl($actor);
        $records=new ServiceContainer($this->factory->createWith(ServiceFactory::class,['user'=>$actor,'acl'=>$acl]));
        $workspace=$this->factory->createWith(MarketingEmailWorkspaceService::class,['user'=>$actor,'acl'=>$acl,'recordServices'=>$records]);
        return $this->factory->createWith(MarketingDispatchService::class,['user'=>$actor,'acl'=>$acl,'workspace'=>$workspace]);
    }
    private function access(): void
    {
        $context=$this->contexts->require();
        if (!$this->user->isAdmin() || !$this->user->get('isActive') || $this->user->get('tenantId')!==$context->tenantId || $this->user->get('serviceId')!==$context->serviceId || !$this->acl->checkScope('MassEmail','edit') || !$this->acl->checkScope('Contact','read')) throw new Forbidden('An active tenant email administrator is required.');
    }
    private function experiment(string $id,bool $lock=false): array
    {
        return $this->one('SELECT * FROM nexa_email_experiment WHERE tenant_id=? AND service_id=? AND id=?'.($lock?' FOR UPDATE':''),[...$this->scope(),$id]) ?? throw new NotFound('Email experiment not found.');
    }
    private function variants(string $id):array{return $this->all('SELECT * FROM nexa_email_experiment_variant WHERE tenant_id=? AND service_id=? AND experiment_id=? ORDER BY variant_key',[...$this->scope(),$id]);}
    private function event(string $id,string $type,array $payload,?string $actor=null):void{$this->execute('INSERT INTO nexa_email_experiment_event (id,tenant_id,service_id,experiment_id,event_type,actor_user_id,payload_json) VALUES (?,?,?,?,?,?,?)',[$this->uuid(),...$this->scope(),$id,$type,$actor??$this->user->getId(),json_encode($payload,JSON_THROW_ON_ERROR)]);}
    private function lockTenant():void{$this->one('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE',[$this->scope()[0]]);}
    private function scope():array{$context=$this->contexts->require();return[$context->tenantId,$context->serviceId];}
    private function all(string $sql,array $args):array{$query=$this->em->getPDO()->prepare($sql);$query->execute($args);return$query->fetchAll(PDO::FETCH_ASSOC);}
    private function one(string $sql,array $args):?array{return$this->all($sql,$args)[0]??null;}
    private function execute(string $sql,array $args):void{$query=$this->em->getPDO()->prepare($sql);$query->execute($args);}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);}
    private function transaction(callable $callback):mixed
    {
        $pdo=$this->em->getPDO();$manager=$this->em->getTransactionManager();$owns=!$pdo->inTransaction();$save='nexa_experiment_'.bin2hex(random_bytes(6));
        if($owns)$manager->start();else$pdo->exec('SAVEPOINT '.$save);
        try{$result=$callback();if($owns)$manager->commit();else$pdo->exec('RELEASE SAVEPOINT '.$save);return$result;}
        catch(\Throwable$error){if($owns&&$manager->isStarted())$manager->rollback();elseif($pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}throw$error;}
    }
}
