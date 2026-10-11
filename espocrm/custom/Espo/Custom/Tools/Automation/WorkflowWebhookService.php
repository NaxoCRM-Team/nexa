<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\AclManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use Espo\ORM\Entity;
use PDO;

final class WorkflowWebhookService
{
    public function __construct(private EntityManager $em,private TenantContextStore $contexts,private ServiceFactory $records,private AclManager $aclManager,private WorkflowWebhookTransport $transport,private WorkflowQuota $quota,private User $user) {}

    public function validate(string $id,User $actor): Entity
    {
        $hook=$this->records->createForUser('Webhook',$actor)->getEntity($id);$context=$this->contexts->require();
        if($hook->get('deleted') || !$hook->get('isActive') || $hook->get('tenantId')!==$context->tenantId || $hook->get('serviceId')!==$context->serviceId)throw new Forbidden('Choose an active native webhook in this workspace.');
        $this->transport->validateUrl((string)$hook->get('url'));
        $internal=$this->em->getEntityById('Webhook',$id);
        if(!is_string($internal?->get('secretKey')) || strlen($internal->get('secretKey'))<16)throw new BadRequest('The webhook needs a native signing secret of at least 16 characters.');
        return $internal;
    }

    /** Caller owns workflow/run locks and a transaction; this never sends a request. */
    public function stage(array $run,array $node,Entity $contact,User $actor): array
    {
        if(!$this->em->getPDO()->inTransaction())throw new \LogicException('Webhook staging requires the enrollment transaction.');
        $prior=$this->one('SELECT * FROM nexa_workflow_webhook_effect WHERE tenant_id=? AND service_id=? AND run_id=? AND node_id=?',[...$this->scope(),$run['id'],$node['id']]);
        if($prior)return $prior;
        $hook=$this->validate($node['settings']['webhookId'],$actor);$id=$this->uuid();
        $body=(object)['event'=>'workflow.webhook','effectId'=>$id,'workflowId'=>$run['workflow_id'],'runId'=>$run['id'],'nodeId'=>$node['id'],'contactId'=>$contact->getId()];
        $queue=$this->em->createEntity('WebhookQueueItem',['event'=>'workflow.webhook','webhookId'=>$hook->getId(),'targetType'=>'Contact','targetId'=>$contact->getId(),'status'=>'Managed','data'=>$body,'attempts'=>0]);
        $this->em->getPDO()->prepare("INSERT INTO nexa_workflow_webhook_effect (id,tenant_id,service_id,workflow_id,run_id,node_id,queue_item_id,webhook_id,execution_user_id,endpoint_hash,payload_hash,max_attempts,retry_seconds,due_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$id,...$this->scope(),$run['workflow_id'],$run['id'],$node['id'],$queue->getId(),$hook->getId(),$actor->getId(),hash('sha256',$hook->get('url')),hash('sha256',$this->json($body)),$node['maxAttempts'],$node['retrySeconds']]);
        $this->audit($run,'webhook.queued',['effectId'=>$id,'queueItemId'=>$queue->getId()],$actor->getId());
        return $this->one('SELECT * FROM nexa_workflow_webhook_effect WHERE id=? AND tenant_id=? AND service_id=?',[$id,...$this->scope()]);
    }

    public function process(): int
    {
        if(!$this->user->isSystem())throw new Forbidden('Webhook dispatch is a background operation.');
        // HTTP leases must be committed before requests; rollback-only fixtures call claim/finalize separately.
        if($this->em->getPDO()->inTransaction())throw new \LogicException('Webhook dispatch cannot run inside another transaction.');
        $query=$this->em->getPDO()->prepare("SELECT id FROM nexa_workflow_webhook_effect WHERE tenant_id=? AND service_id=? AND ((status='pending' AND due_at<=UTC_TIMESTAMP(6)) OR (status='sending' AND lease_expires_at<=UTC_TIMESTAMP(6))) ORDER BY due_at,id LIMIT 5");$query->execute($this->scope());$count=0;
        foreach($query->fetchAll(PDO::FETCH_COLUMN) as $id){
            $claimed=$this->atomic(fn()=>$this->claim($id));if(!$claimed)continue;
            try{$result=$this->transport->send($claimed['url'],$claimed['payload'],$claimed['id'],$claimed['secret']);}
            catch(\Throwable $error){$result=['outcome'=>'uncertain','code'=>'transport_exception','status'=>null];}
            $this->atomic(fn()=>$this->finalize($id,$claimed['token'],$result));$count++;
        }
        return $count;
    }

    public function claim(string $id): ?array
    {
        if(!$this->user->isSystem() || !$this->em->getPDO()->inTransaction())throw new Forbidden('A worker transaction is required.');
        try{$limits=$this->quota->limits(true);}catch(Forbidden $error){return null;}
        $candidate=$this->one('SELECT workflow_id,run_id FROM nexa_workflow_webhook_effect WHERE id=? AND tenant_id=? AND service_id=?',[$id,...$this->scope()]);if(!$candidate)return null;
        $workflow=$this->one('SELECT status FROM nexa_workflow WHERE id=? AND tenant_id=? AND service_id=? FOR UPDATE',[$candidate['workflow_id'],...$this->scope()]);
        $run=$this->one('SELECT * FROM nexa_workflow_run WHERE id=? AND tenant_id=? AND service_id=? FOR UPDATE',[$candidate['run_id'],...$this->scope()]);
        $effect=$this->one('SELECT * FROM nexa_workflow_webhook_effect WHERE id=? AND tenant_id=? AND service_id=? FOR UPDATE',[$id,...$this->scope()]);
        if($effect['status']==='sending' && $this->one('SELECT 1 FROM nexa_workflow_webhook_effect WHERE id=? AND lease_expires_at<=UTC_TIMESTAMP(6)',[$id])) {
            $this->state($effect,'uncertain','lease_expired');$this->attemptResult($effect,'uncertain',null,'lease_expired');$this->audit($run,'webhook.uncertain',['effectId'=>$id,'code'=>'lease_expired'],$effect['execution_user_id']);return null;
        }
        if($effect['status']!=='pending' || !$this->one('SELECT 1 FROM nexa_workflow_webhook_effect WHERE id=? AND due_at<=UTC_TIMESTAMP(6)',[$id]))return null;
        if(in_array($run['status'],['cancelled','failed','completed'],true) || $workflow['status']==='archived'){$this->state($effect,'cancelled','enrollment_stopped');return null;}
        if($workflow['status']!=='published')return null;
        $active=$this->em->getPDO()->prepare("SELECT id FROM nexa_workflow_webhook_effect WHERE tenant_id=? AND status='sending' FOR UPDATE");$active->execute([$this->contexts->require()->tenantId]);
        if(count($active->fetchAll(PDO::FETCH_COLUMN))>=$limits['maxConcurrentWebhooks'])return null;
        try {
            $actor=$this->em->getEntityById('User',$effect['execution_user_id']);$context=$this->contexts->require();
            if(!$actor instanceof User || !$actor->isActive() || $actor->getType()!==User::TYPE_ADMIN || $actor->get('deleted') || $actor->get('tenantId')!==$context->tenantId || $actor->get('serviceId')!==$context->serviceId)throw new Forbidden('Execution identity unavailable.');
            $hook=$this->validate($effect['webhook_id'],$actor);
            $contact=$this->records->createForUser('Contact',$actor)->getEntity($run['contact_id']);
            if($contact->get('deleted') || $contact->get('tenantId')!==$context->tenantId || $contact->get('serviceId')!==$context->serviceId)throw new Forbidden('Contact unavailable.');
            $queue=$this->em->getEntityById('WebhookQueueItem',$effect['queue_item_id']);
            if(!$queue || $queue->get('status')!=='Managed' || $queue->get('tenantId')!==$context->tenantId || $queue->get('serviceId')!==$context->serviceId || $queue->get('webhookId')!==$hook->getId() || $queue->get('targetType')!=='Contact' || $queue->get('targetId')!==$contact->getId())throw new Forbidden('Queue identity invalid.');
            $payload=$this->json($queue->get('data'));
            if(!hash_equals($effect['endpoint_hash'],hash('sha256',$hook->get('url'))) || !hash_equals($effect['payload_hash'],hash('sha256',$payload)))throw new BadRequest('Delivery snapshot changed.');
        } catch(Forbidden|NotFound|BadRequest $error){$this->state($effect,'failed','authorization_or_snapshot_invalid');$this->audit($run,'webhook.failed',['effectId'=>$id,'code'=>'authorization_or_snapshot_invalid'],$effect['execution_user_id']);return null;}
        $token=bin2hex(random_bytes(32));
        $this->em->getPDO()->prepare("UPDATE nexa_workflow_webhook_effect SET status='sending',attempts=attempts+1,lease_token=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 SECOND),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?")->execute([$token,$id,...$this->scope()]);
        $this->em->getPDO()->prepare("INSERT INTO nexa_workflow_webhook_attempt (tenant_id,service_id,effect_id,attempt_number,outcome,started_at) VALUES (?,?,?,?,'sending',UTC_TIMESTAMP(6))")->execute([...$this->scope(),$id,(int)$effect['attempts']+1]);
        $this->audit($run,'webhook.attempted',['effectId'=>$id,'attempt'=>(int)$effect['attempts']+1],$actor->getId());
        return ['id'=>$id,'token'=>$token,'url'=>$hook->get('url'),'secret'=>$hook->get('secretKey'),'payload'=>$payload];
    }

    public function finalize(string $id,string $token,array $result): void
    {
        if(!$this->user->isSystem() || !$this->em->getPDO()->inTransaction())throw new Forbidden('A worker transaction is required.');
        $effect=$this->one('SELECT * FROM nexa_workflow_webhook_effect WHERE id=? AND tenant_id=? AND service_id=? FOR UPDATE',[$id,...$this->scope()]);
        if(!$effect || $effect['status']!=='sending' || !hash_equals((string)$effect['lease_token'],$token))return;
        $outcome=$result['outcome'] ?? '';
        if(!in_array($outcome,['success','failed','uncertain','not_sent'],true))throw new BadRequest('Invalid transport outcome.');
        $status=$outcome==='not_sent'?((int)$effect['attempts']<(int)$effect['max_attempts']?'pending':'failed'):$outcome;
        $this->state($effect,$status,$result['code'] ?? null,$result['status'] ?? null);
        if($status==='pending')$this->em->getPDO()->prepare('UPDATE nexa_workflow_webhook_effect SET due_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND) WHERE id=? AND tenant_id=? AND service_id=?')->execute([min(86400,(int)$effect['retry_seconds']*(2**max(0,(int)$effect['attempts']-1))),$id,...$this->scope()]);
        $this->attemptResult($effect,$outcome,$result['status'] ?? null,$result['code'] ?? null);
        $this->audit(['workflow_id'=>$effect['workflow_id'],'id'=>$effect['run_id'],'node_id'=>$effect['node_id']],'webhook.'.$status,['effectId'=>$id,'attempt'=>(int)$effect['attempts'],'code'=>$result['code'] ?? null,'httpStatus'=>$result['status'] ?? null],$effect['execution_user_id']);
    }

    public function cancel(string $workflowId,string $runId): void
    {
        $query=$this->em->getPDO()->prepare("SELECT * FROM nexa_workflow_webhook_effect WHERE tenant_id=? AND service_id=? AND workflow_id=? AND run_id=? AND status='pending' FOR UPDATE");$query->execute([...$this->scope(),$workflowId,$runId]);
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $effect)$this->state($effect,'cancelled','enrollment_cancelled');
        // An already claimed request cannot be recalled; its evidence is finalized without resending.
    }

    public function resolve(string $workflowId,string $runId,string $outcome,string $evidence,User $actor): void
    {
        if($actor->getType()!==User::TYPE_ADMIN || !in_array($outcome,['success','failed'],true) || strlen(trim($evidence))<10 || strlen($evidence)>1000)throw new BadRequest('Supply delivery evidence and choose delivered or failed. This does not resend.');
        $query=$this->em->getPDO()->prepare("SELECT * FROM nexa_workflow_webhook_effect WHERE tenant_id=? AND service_id=? AND workflow_id=? AND run_id=? AND status='uncertain' FOR UPDATE");$query->execute([...$this->scope(),$workflowId,$runId]);$effects=$query->fetchAll(PDO::FETCH_ASSOC);
        if(count($effects)!==1)throw new BadRequest('There is no single uncertain delivery to review.');
        $effect=$effects[0];$this->state($effect,$outcome,'operator_review');
        $this->audit(['workflow_id'=>$workflowId,'id'=>$runId,'node_id'=>$effect['node_id']],'webhook.reviewed',['effectId'=>$effect['id'],'outcome'=>$outcome,'evidenceHash'=>hash('sha256',trim($evidence))],$actor->getId());
    }

    private function state(array $effect,string $status,?string $code,?int $httpStatus=null): void
    {
        $this->em->getPDO()->prepare('UPDATE nexa_workflow_webhook_effect SET status=?,last_error_code=?,last_http_status=?,lease_token=NULL,lease_expires_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=?')->execute([$status,$code,$httpStatus,$effect['id'],...$this->scope()]);
        $nativeStatus=match($status){'success'=>'Success','failed','cancelled'=>'Failed',default=>'Managed'};
        $this->em->getPDO()->prepare('UPDATE webhook_queue_item SET status=?,attempts=?,processed_at=UTC_TIMESTAMP() WHERE id=? AND tenant_id=? AND service_id=?')->execute([$nativeStatus,$effect['attempts'],$effect['queue_item_id'],...$this->scope()]);
    }
    private function attemptResult(array $effect,string $outcome,?int $status,?string $code): void
    {$this->em->getPDO()->prepare('UPDATE nexa_workflow_webhook_attempt SET outcome=?,http_status=?,error_code=?,finished_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND effect_id=? AND attempt_number=?')->execute([$outcome,$status,$code,...$this->scope(),$effect['id'],$effect['attempts']]);}
    private function audit(array $run,string $event,array $payload,string $actor): void
    {$this->em->getPDO()->prepare('INSERT INTO nexa_workflow_event (id,tenant_id,service_id,workflow_id,run_id,event_type,node_id,actor_user_id,payload_json,created_at) VALUES (UUID(),?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([...$this->scope(),$run['workflow_id'],$run['id'],$event,$run['node_id'],$actor,$this->json((object)$payload)]);}
    private function one(string $sql,array $params): ?array {$query=$this->em->getPDO()->prepare($sql);$query->execute($params);return $query->fetch(PDO::FETCH_ASSOC) ?: null;}
    private function scope(): array {$context=$this->contexts->require();return [$context->tenantId,$context->serviceId];}
    private function json(mixed $value): string {return json_encode($value,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);}
    private function uuid(): string {return $this->em->getPDO()->query('SELECT UUID()')->fetchColumn();}
    private function atomic(callable $callback): mixed
    {$pdo=$this->em->getPDO();$pdo->beginTransaction();try{$result=$callback();$pdo->commit();return $result;}catch(\Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}}
}
